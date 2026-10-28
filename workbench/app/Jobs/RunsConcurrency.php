<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Concurrency;

/** Fixture: Concurrency::run with two closures on the fork driver (spatie/fork) inside a worker. */
class RunsConcurrency implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        $results = Concurrency::driver('fork')->run([
            fn () => ['pid' => getmypid(), 'ppid' => posix_getppid(), 'value' => 6 * 7],
            fn () => ['pid' => getmypid(), 'ppid' => posix_getppid(), 'value' => strrev('krof')],
        ]);

        JobResult::record('RunsConcurrency', ['parent' => getmypid(), 'results' => $results]);
    }
}
