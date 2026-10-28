<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;

/**
 * What a freshly forked child (a master, supervisor loop or worker) does before it becomes a stock process.
 *
 * It resets signal state, closes the package's channels that don't belong to it (and nothing else:
 * stdio and opcache's lock descriptor stay), waits to be admitted, applies the spawn context and its
 * identity, and returns the entry script to run at the template script's top level.
 */
final class ChildRoutine
{
    /**
     * The signals the servers handle or ignore (Signals::install). No others: PHP's own handlers stay,
     * such as its max_execution_time timer on SIGPROF (SIGALRM on Apple Silicon).
     */
    private const RESET_SIGNALS = [SIGTERM, SIGINT, SIGQUIT, SIGHUP, SIGUSR1, SIGUSR2, SIGCONT, SIGCHLD];

    /**
     * @param  resource  $own  this child's own socket
     * @param  list<resource|Channel|null>  $close  package-owned channel ends that are not this child's
     * @param  array<string, string>  $env
     * @param  (callable(): void)|null  $afterAdmit  queue:work children set up their stdio, process group
     *                                               and limits here
     * @param  string|null  $prepend  the requester's own auto_prepend_file; null reads HP_ORIGINAL_PREPEND
     * @param  string|null  $entry  the script to run, already resolved and checked; null resolves it from $cwd
     * @param  list<int>  $ignore  signals the requester ignores (a hosted master's, under nohup)
     */
    public static function become(
        string $role,
        $own,
        array $close,
        CommandLine $command,
        string $cwd,
        array $env,
        string $requestId,
        ?callable $afterAdmit = null,
        ?string $prepend = null,
        ?string $entry = null,
        array $ignore = [],
    ): ChildJob {
        Runtime::$role = $role;

        foreach (self::RESET_SIGNALS as $signal) {
            pcntl_signal($signal, in_array($signal, $ignore, true) ? SIG_IGN : SIG_DFL);
        }

        pcntl_signal(SIGPIPE, SIG_IGN); // as the PHP CLI starts: a write to a closed pipe is an error, not a death

        pcntl_alarm(0);
        pcntl_sigprocmask(SIG_SETMASK, []);

        foreach ($close as $stream) {
            if ($stream instanceof Channel) {
                $stream->close();
            } elseif (is_resource($stream)) {
                @fclose($stream);
            }
        }

        $server = posix_getppid();
        Runtime::$serverPid = $server;
        Runtime::$serverStart = Identity::startTime($server);
        Runtime::$requestId = $requestId;

        // Admission: run nothing with side effects until the requester says so.
        $channel = new Channel($own);
        $message = $channel->receiveOne();

        if ($message === null || ($message['t'] ?? null) !== 'admit') {
            RoleGuard::shim(75);
        }

        Runtime::$channel = $channel;
        Runtime::$paused = (bool) ($message['paused'] ?? false);

        if ($role === 'unit-loop') {
            Runtime::$unitId = Identity::key($server, Runtime::$serverStart);
        }

        // Read before the requester's environment replaces this one.
        $prepend ??= (string) getenv('HP_ORIGINAL_PREPEND');
        Runtime::$prepend = $prepend;

        if ($afterAdmit !== null) {
            $afterAdmit();
        }

        self::applyEnv($env);

        if ($cwd !== '' && is_dir($cwd)) {
            chdir($cwd);
        }

        mt_srand();
        memory_reset_peak_usage();
        @cli_set_process_title($command->title());

        return new ChildJob($entry ?? $command->entry($cwd), $command->script, $command->argv, $prepend);
    }

    /**
     * Makes the environment exactly the requester's, as a stock exec would inherit it.
     *
     * @param  array<string, string>  $env
     */
    public static function applyEnv(array $env): void
    {
        if ($env === []) {
            return;
        }

        foreach (array_keys(getenv()) as $name) {
            if (! array_key_exists($name, $env)) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        foreach ($env as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
