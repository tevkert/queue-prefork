<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fixture: records one row and returns. Batchable, so DispatchesBatch can use it. */
class Succeeds implements ShouldQueue
{
    use Batchable, Queueable;

    public function __construct(public string $tag = '')
    {
    }

    public function handle(): void
    {
        JobResult::record('Succeeds', [
            'tag' => $this->tag,
            'attempt' => $this->attempts(),
            'batch' => $this->batchId,
        ]);
    }
}
