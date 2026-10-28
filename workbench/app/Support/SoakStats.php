<?php

namespace App\Support;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Per-container job counters in one Redis hash, `<REDIS_PREFIX>soak:stats`, on the default connection:
 *   processed, failed                       totals
 *   processed:<queue>, runtime_ms:<queue>   per queue (runtime summed over processed and failed jobs)
 *
 * soak/sample.php reads the hash every minute and turns the deltas into jobs/minute and mean runtime.
 * A failure to count never affects the job.
 */
class SoakStats
{
    public const KEY = 'soak:stats';

    /** @var array<string, int> hrtime() at JobProcessing, by job id */
    private static array $started = [];

    public static function processing(JobProcessing $event): void
    {
        self::$started[$event->job->getJobId()] = hrtime(true);
    }

    public static function processed(JobProcessed $event): void
    {
        self::count('processed', $event->job->getQueue(), $event->job->getJobId());
    }

    public static function failed(JobFailed $event): void
    {
        self::count('failed', $event->job->getQueue(), $event->job->getJobId());
    }

    private static function count(string $outcome, ?string $queue, $jobId): void
    {
        $started = self::$started[$jobId] ?? null;
        unset(self::$started[$jobId]);
        $ms = $started === null ? 0 : intdiv(hrtime(true) - $started, 1_000_000);
        $queue = $queue ?: 'unknown';

        try {
            Redis::connection('default')->pipeline(function ($pipe) use ($outcome, $queue, $ms) {
                $pipe->hincrby(self::KEY, $outcome, 1);
                $pipe->hincrby(self::KEY, $outcome.':'.$queue, 1);
                $pipe->hincrby(self::KEY, 'runtime_ms:'.$queue, $ms);
            });
        } catch (Throwable) {
            // Counting is best-effort.
        }
    }
}
