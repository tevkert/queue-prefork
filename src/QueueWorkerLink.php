<?php

namespace Tevkert\QueuePrefork;

use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\LogTargets;
use Throwable;

/**
 * A forked `queue:work` worker's side of the per-worker fork.
 *
 * Once the app has booted, before the command runs (from Wiring, not from an event, so no listener can
 * skip it), the template's warm-up boot leaves, and a worker may still decline prefork: the post-boot
 * switch is off, or (shared-log mode) the app logs to stdout/stderr. It then reports `stock` and exits;
 * its stub execs a stock worker in place. On Looping it watches its stub at most once a second: a stub
 * that's gone means this worker stops before reserving another job, backing up the template's kill.
 */
final class QueueWorkerLink
{
    private static float $lastWatch = 0.0;

    public static function booted(object $app): void
    {
        if (Runtime::$warmup) {
            // The template's warm-up boot: the app is compiled into its opcache now. Resolve the worker's
            // own classes too, each on its own (the command, with queue.worker and cache.store, from the
            // container, as Artisan hasn't registered its commands yet; the default connection, which
            // does no I/O until first use). Then leave before any job.
            $resolvers = [fn () => $app->make(\Illuminate\Queue\Console\WorkCommand::class), fn () => $app['queue']->connection()];

            foreach ($resolvers as $resolve) {
                try {
                    $resolve();
                } catch (Throwable) {
                }
            }

            Runtime::$channel?->send(['t' => 'ready', 'id' => 'warmup']);
            Runtime::$channel?->flushBlocking(1.0);

            exit(0);
        }

        $reason = self::declineReason($app);

        if ($reason !== null) {
            self::decline($reason);
        }

        // Booted and staying. From here an exit is no boot crash (the template's stock fallback and
        // breaker count only what dies before this), however the app's event listeners behave later.
        Runtime::$channel?->send(['t' => 'ready', 'id' => Runtime::$requestId]);
        Runtime::$channel?->flushBlocking(1.0);
    }

    public static function looping(): ?bool
    {
        $now = microtime(true);

        if ($now - self::$lastWatch >= 1.0) {
            self::$lastWatch = $now;
            Identity::forgetCache();

            if (Runtime::$stubPid !== null && ! Identity::alive(Runtime::$stubPid, Runtime::$stubStart)) {
                posix_kill(getmypid(), SIGTERM); // Laravel's own handler: finish nothing new, then stop

                return false; // and this loop doesn't pop
            }
        }

        return WorkerLink::looping();
    }

    private static function declineReason(object $app): ?string
    {
        if (! Settings::enabled($app, 'queue-work')) {
            return 'switch';
        }

        if (Runtime::$output === 'shared-log' && LogTargets::defaultChannelWritesToStdio($app, Runtime::$stubStdio)) {
            return 'logs-to-stdio';
        }

        return null;
    }

    private static function decline(string $reason): never
    {
        Runtime::$channel?->send(['t' => 'stock', 'reason' => $reason]);
        Runtime::$channel?->flushBlocking(1.0);

        exit(0);
    }
}
