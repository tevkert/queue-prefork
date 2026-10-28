<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Runtime;

/**
 * The first shutdown function in every prefork process.
 *
 * In the template and fork servers, any exit, fatal errors included, replaces the process with
 * `/bin/sh -c 'exit N'`, so no inherited shutdown code ever runs there. In the forked children it only
 * arms the exit watchdog, so a shutdown that hangs becomes SIGALRM (status 142) instead of a hang.
 */
final class RoleGuard
{
    private static bool $installed = false;

    public static ?int $exitCode = null;

    public static function install(): void
    {
        if (self::$installed) {
            return;
        }

        self::$installed = true;
        register_shutdown_function([self::class, 'onShutdown']);
    }

    public static function onShutdown(): void
    {
        if (Runtime::isServer()) {
            self::shim(self::$exitCode ?? 70);
        }

        if (Runtime::isChild()) {
            self::armWatchdog();
        }
    }

    /** Ends a server process without running any shutdown code. */
    public static function exitServer(int $code): never
    {
        self::$exitCode = $code;
        self::shim($code);
    }

    public static function shim(int $code): never
    {
        $code &= 0xFF;

        if (function_exists('pcntl_exec')) {
            @pcntl_exec('/bin/sh', ['-c', 'exit '.$code]);
        }

        // exec failed: still never run app shutdown code.
        posix_kill(getmypid(), SIGKILL);

        exit($code);
    }

    public static function armWatchdog(): void
    {
        if (! function_exists('pcntl_alarm')) {
            return;
        }

        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_alarm(max(1, (int) Runtime::config('exit_grace', 15)));
    }
}
