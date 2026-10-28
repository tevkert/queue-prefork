<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fixture: the worker dies mid-job, with exit(255) or a SIGSEGV. The job stays reserved until the
 * queue's retry_after and is then failed (tries 1) by another worker.
 */
class Crashes implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $mode = 'exit')
    {
    }

    public function handle(): void
    {
        JobResult::record('Crashes', ['mode' => $this->mode]);

        if ($this->mode === 'segv') {
            posix_kill(getmypid(), SIGSEGV);
            sleep(5); // the signal is delivered asynchronously

            return;
        }

        exit(255);
    }
}
