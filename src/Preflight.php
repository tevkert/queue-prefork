<?php

namespace Tevkert\QueuePrefork;

use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Laravel\Horizon\ProcessPool;
use Laravel\Horizon\SupervisorCommandString;
use Laravel\Horizon\SupervisorFactory;
use Laravel\Horizon\WorkerCommandString;
use Symfony\Component\Process\Process;
use Tevkert\QueuePrefork\Support\CommandLine;
use Throwable;

/**
 * Checks run in the master before the template is spawned. Any reason means stock mode.
 */
final class Preflight
{
    /** Newest Horizon 5 minor the suites have passed on. */
    public const TESTED_HORIZON_MINOR = 50;

    /** @return list<string> */
    public static function check(Application $app, bool $probes = true): array
    {
        $reasons = [];
        $config = (array) $app['config']->get('queue-prefork', []);

        if (! in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            $reasons[] = 'unsupported platform '.PHP_OS_FAMILY;
        }

        if (getmypid() === 1) {
            $reasons[] = 'the master runs as PID 1 (run Horizon under tini or supervisord)';
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        $functions = [
            'pcntl_fork', 'pcntl_exec', 'pcntl_waitpid', 'pcntl_signal', 'pcntl_sigprocmask', 'posix_kill', 'proc_open',
            'stream_socket_pair', 'cli_set_process_title',
        ];

        foreach ($functions as $function) {
            if (! function_exists($function) || in_array($function, $disabled, true)) {
                $reasons[] = "{$function}() is unavailable";
            }
        }

        if (! is_executable('/bin/sh')) {
            $reasons[] = '/bin/sh is missing';
        }

        $reasons = array_merge($reasons, self::horizonVersion($config), self::bindings($app), self::commandStrings($app), self::extensions($config));

        if ($probes && $reasons === []) {
            $reasons = array_merge($reasons, self::probes($app));
        }

        return $reasons;
    }

    /** @return list<string> */
    private static function horizonVersion(array $config): array
    {
        try {
            $version = (string) InstalledVersions::getVersion('laravel/horizon');
        } catch (Throwable) {
            return [];
        }

        if (! preg_match('/^v?(\d+)\.(\d+)/', $version, $m)) {
            return [];
        }

        if ((int) $m[1] !== 5) {
            return ["unsupported Horizon {$version}"];
        }

        if ((int) $m[2] > self::TESTED_HORIZON_MINOR && ! ($config['allow_untested_horizon'] ?? false)) {
            return ["Horizon {$version} is newer than the tested 5.".self::TESTED_HORIZON_MINOR.' (set allow_untested_horizon to try it)'];
        }

        return [];
    }

    /** @return list<string> */
    private static function bindings(Application $app): array
    {
        $reasons = [];

        foreach ([SupervisorFactory::class, AddSupervisor::class, ProcessPool::class] as $abstract) {
            if ($app->bound($abstract)) {
                $reasons[] = "the app binds {$abstract} itself";
            }
        }

        return $reasons;
    }

    /** @return list<string> */
    private static function commandStrings(Application $app): array
    {
        $reasons = [];

        foreach (['supervisor' => SupervisorCommandString::$command, 'worker' => WorkerCommandString::$command] as $kind => $line) {
            if (! preg_match('/^(exec\s+)?@php\s+(\S+)\s+horizon:(work|supervisor)$/', trim($line), $m)) {
                $reasons[] = "unsupported {$kind} command string [{$line}]";

                continue;
            }

            $entry = str_starts_with($m[2], '/') ? $m[2] : $app->basePath($m[2]);

            if (! is_file($entry)) {
                $reasons[] = "{$kind} entry script {$entry} not found";
            } elseif (preg_match('/\bgetopt\s*\(/', (string) file_get_contents($entry))) {
                $reasons[] = "{$kind} entry script {$entry} calls getopt(), which can't see a forked child's arguments";
            }
        }

        return $reasons;
    }

    /**
     * Fork-unsafe extensions and environments; needs no application, so the shared template
     * checks it too.
     *
     * @return list<string>
     */
    public static function extensions(array $config): array
    {
        $reasons = [];

        if (extension_loaded('newrelic') && ini_get('newrelic.enabled')) {
            $reasons[] = 'New Relic is not fork-aware in the CLI';
        }

        if (extension_loaded('grpc') && ! ini_get('grpc.enable_fork_support')) {
            $reasons[] = 'ext-grpc without grpc.enable_fork_support=1';
        }

        if (extension_loaded('datadog-profiling') && ini_get('datadog.profiling.enabled') && ! ($config['allow_unprofiled_workers'] ?? false)) {
            $reasons[] = 'datadog-profiling turns profiling off in forked children';
        }

        if (getenv('BLACKFIRE_QUERY')) {
            $reasons[] = 'BLACKFIRE_QUERY is set';
        }

        return $reasons;
    }

    /**
     * Both entries must install the package's hook: run each once with HP_ROLE=probe.
     *
     * @return list<string>
     */
    public static function probes(Application $app): array
    {
        $reasons = [];

        foreach (['unit-loop' => SupervisorCommandString::$command, 'worker' => WorkerCommandString::$command] as $role => $line) {
            try {
                $words = CommandLine::split(str_replace('@php', 'php', $line));

                if (($words[0] ?? null) === 'exec') {
                    array_shift($words);
                }

                $script = $words[1] ?? 'artisan';
                $process = new Process([PHP_BINARY, $script, 'hp:probe', '--role='.$role], $app->basePath(), ['HP_ROLE' => 'probe'] + getenv(), null, 30);
                $process->run();
                $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
                $result = json_decode((string) end($lines), true);

                if (! is_array($result) || ($result['ok'] ?? false) !== true) {
                    $reasons[] = "probe of {$script} ({$role}) failed: ".trim($process->getErrorOutput() ?: $process->getOutput());
                }
            } catch (Throwable $e) {
                $reasons[] = "probe of the {$role} entry failed: ".$e->getMessage();
            }
        }

        return $reasons;
    }
}
