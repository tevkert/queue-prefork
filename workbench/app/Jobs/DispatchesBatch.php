<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;

/** Fixture: dispatches a batch of two Succeeds from inside a worker; the `then` callback records too. */
class DispatchesBatch implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        $batch = Bus::batch([
            new Succeeds('batch-1'),
            new Succeeds('batch-2'),
        ])->then(function (Batch $batch) {
            JobResult::record('DispatchesBatch.then', ['batch' => $batch->id, 'processed' => $batch->processedJobs()]);
        })->name('workbench-fixture')->onQueue($this->queue ?? 'default')->dispatch();

        JobResult::record('DispatchesBatch', ['batch' => $batch->id]);
    }
}
