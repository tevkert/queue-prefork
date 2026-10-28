<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/** Fixture: records its attempt and throws; failed() records the failure in the worker too. */
class Fails implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        JobResult::record('Fails', ['attempt' => $this->attempts()]);

        throw new RuntimeException('Fails fixture: intentional failure');
    }

    public function failed(?Throwable $e): void
    {
        JobResult::record('Fails.failed', ['message' => $e?->getMessage()]);
    }
}
