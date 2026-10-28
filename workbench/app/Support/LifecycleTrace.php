<?php

namespace App\Support;

/**
 * Appends JSON lines to storage/logs/lifecycle.log: what a process saw about itself at a lifecycle
 * point. Used by the workbench's own hooks (HorizonServiceProvider) so tests can compare stock and
 * forked processes.
 */
class LifecycleTrace
{
    public static function write(string $event, array $data = []): void
    {
        $line = json_encode([
            'event' => $event,
            'time' => microtime(true),
            'pid' => getmypid(),
            'ppid' => function_exists('posix_getppid') ? posix_getppid() : null,
            'argv' => $_SERVER['argv'] ?? null,
            'app' => app()::class,
            ...$data,
        ], JSON_UNESCAPED_SLASHES);

        @file_put_contents(storage_path('logs/lifecycle.log'), $line."\n", FILE_APPEND | LOCK_EX);
    }
}
