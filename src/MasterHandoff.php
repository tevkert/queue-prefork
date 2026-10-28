<?php

namespace Tevkert\QueuePrefork;

use Tevkert\QueuePrefork\Server\Signals;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\TemplateKey;
use Throwable;

/**
 * The Horizon master's handoff, run by the hook before Laravel boots.
 *
 * `php artisan horizon` execs, keeping its pid, into a private template (Server\Template in host mode)
 * that forks the master: the master then shares the template's compiled code instead of compiling the
 * app for itself. Returns only when this process should start the master as before (the template
 * spawned as its child), and never throws.
 */
final class MasterHandoff
{
    /** The variables the handoff adds for the template; the master and a stock exec get none of them. */
    public const ENV = [
        'QP_SERVER',
        'QP_SETTINGS',
        'QP_INI',
        'QP_IGNORED',
        'QP_MASTER_COMMAND',
        'QP_MASTER_ENTRY',
        'HP_ORIGINAL_PREPEND',
        'HP_PACKAGE_DIR',
    ];

    /** What the template calls besides PHP itself. */
    private const FUNCTIONS = [
        'pcntl_fork',
        'pcntl_exec',
        'pcntl_waitpid',
        'pcntl_signal',
        'pcntl_signal_dispatch',
        'pcntl_sigprocmask',
        'pcntl_alarm',
        'pcntl_signal_get_handler',
        'pcntl_wifsignaled',
        'pcntl_wtermsig',
        'pcntl_wexitstatus',
        'posix_kill',
        'posix_getppid',
        'posix_setpgid',
        'stream_socket_pair',
        'cli_set_process_title',
    ];

    public static function attempt(): void
    {
        try {
            $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

            if ($script !== false && self::reasons(dirname($script)) === []) {
                self::exec($script);
            }
        } catch (Throwable) {
            // Start the master as before.
        }
    }

    /** @return list<string> why this master can't be handed off; empty when it can */
    public static function reasons(string $release): array
    {
        $reasons = Handoff::blockers($release, 'horizon', self::FUNCTIONS);

        if (PHP_OS_FAMILY !== 'Linux') {
            $reasons[] = 'only Linux has the command line to start the template with the master\'s PHP options';
        }

        if (getmypid() === 1) {
            $reasons[] = 'the master is PID 1';
        }

        return $reasons;
    }

    private static function exec(string $entry): void
    {
        $runtime = RuntimeDir::shared();
        RuntimeDir::ensure($runtime);
        $template = RuntimeDir::copy($runtime, 'template', 'php', (string) file_get_contents(dirname(__DIR__).'/bin/template.php'));
        $ini = RuntimeDir::copy($runtime, 'ini', 'json', json_encode(TemplateKey::iniValues(), JSON_UNESCAPED_SLASHES));
        $settings = TemplateKey::settings(dirname($entry));
        $argv = Handoff::phpOptions();

        foreach (TemplateKey::forcedIni($settings) as $setting) {
            $argv[] = '-d';
            $argv[] = $setting;
        }

        // Room in the process title for the long command lines of the units the template forks.
        array_push($argv, '-d', 'hp.pad='.str_repeat('.', 4096));
        $argv[] = $template; // no "horizon" in the template's own command line: horizon:purge

        $env = array_merge(getenv(), [
            'QP_SERVER' => 'master',
            'QP_SETTINGS' => json_encode($settings),
            'QP_INI' => $ini,
            'QP_IGNORED' => implode(',', Signals::ignored()),
            'QP_MASTER_COMMAND' => json_encode(Handoff::commandLine(), JSON_UNESCAPED_SLASHES),
            'QP_MASTER_ENTRY' => $entry,
            'HP_ORIGINAL_PREPEND' => (string) ini_get('auto_prepend_file'),
            'HP_PACKAGE_DIR' => dirname(__DIR__),
        ]);

        if (function_exists('set_time_limit')) {
            set_time_limit(0); // an armed interval timer would survive the exec
        }

        @pcntl_exec(PHP_BINARY, $argv, $env);
    }
}
