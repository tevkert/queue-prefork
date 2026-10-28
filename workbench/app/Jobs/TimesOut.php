<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Fixture: runs past its own $timeout, so the worker's SIGALRM handler fails the job and kills the
 * worker. `TimesOut.finished` must never appear. It waits in a loop: a signal (Horizon's auto-balancer
 * TERMs workers) cuts a sleep() short, and the job would then finish before its timeout.
 */
class TimesOut implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public $timeout = 2;

    public $failOnTimeout = true;

    public function handle(): void
    {
        JobResult::record('TimesOut.started', ['timeout' => $this->timeout]);

        $until = microtime(true) + $this->timeout + 10;

        while (microtime(true) < $until) {
            usleep(100000);
        }

        JobResult::record('TimesOut.finished');
    }

    public function failed(?Throwable $e): void
    {
        JobResult::record('TimesOut.failed', ['exception' => $e ? $e::class : null]);
    }
}
