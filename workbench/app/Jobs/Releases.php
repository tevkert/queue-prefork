<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fixture: releases itself back to the queue on its first attempt, then succeeds on the second. */
class Releases implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public function __construct(public int $releaseDelay = 1)
    {
    }

    public function handle(): void
    {
        $attempt = $this->attempts();

        if ($attempt < 2) {
            JobResult::record('Releases', ['attempt' => $attempt, 'released' => true]);
            $this->release($this->releaseDelay);

            return;
        }

        JobResult::record('Releases', ['attempt' => $attempt, 'released' => false]);
    }
}
