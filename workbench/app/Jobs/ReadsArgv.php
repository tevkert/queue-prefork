<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fixture: records the worker's view of its own identity: argv in every form a
 * script can read it, the script globals, the pid stored by the autoload.files helper, the
 * auto_prepend marker, constants defined before the autoloader, the title, the application class.
 */
class ReadsArgv implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        $opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;

        JobResult::record('ReadsArgv', [
            'server_argv' => $_SERVER['argv'] ?? null,
            'server_argc' => $_SERVER['argc'] ?? null,
            'globals_argv' => $GLOBALS['argv'] ?? null,
            'globals_argc' => $GLOBALS['argc'] ?? null,
            'request_argv' => request()->server('argv'),
            'script_name' => $_SERVER['SCRIPT_NAME'] ?? null,
            'script_filename' => $_SERVER['SCRIPT_FILENAME'] ?? null,
            'php_self' => $_SERVER['PHP_SELF'] ?? null,
            'request_time_float' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null,
            'laravel_start' => defined('LARAVEL_START') ? LARAVEL_START : null,
            'pid' => getmypid(),
            'ppid' => posix_getppid(),
            'autoload_pid' => $GLOBALS['__workbench_autoload_pid'] ?? null,
            'prepend' => $GLOBALS['__workbench_prepend'] ?? null,
            'title' => cli_get_process_title(),
            'cwd' => getcwd(),
            'nice' => pcntl_getpriority(),
            'app' => app()::class,
            'admin_provider' => app()->providerIsLoaded(\App\Providers\Filament\AdminPanelProvider::class),
            'opcache_enabled' => is_array($opcache) ? (bool) ($opcache['opcache_enabled'] ?? false) : false,
            'queue_prefork_env' => getenv('QUEUE_PREFORK'),
        ]);
    }
}
