<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fixture: sleeps for $seconds. Records `Sleeps.started` before and `Sleeps` after, so a test can
 * terminate Horizon mid-job and check the job still finished exactly once. A signal handler cuts a
 * plain sleep() short; a steady sleeper sleeps its full time regardless (a job that outlasts a stop).
 */
class Sleeps implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $seconds = 3, public bool $steady = false)
    {
    }

    public function handle(): void
    {
        $started = microtime(true);
        JobResult::record('Sleeps.started', ['seconds' => $this->seconds]);

        if ($this->steady) {
            while (($left = $started + $this->seconds - microtime(true)) > 0) {
                usleep((int) (min($left, 0.5) * 1_000_000));
            }
        } else {
            sleep($this->seconds);
        }

        JobResult::record('Sleeps', ['seconds' => $this->seconds, 'elapsed' => round(microtime(true) - $started, 3)]);
    }
}
