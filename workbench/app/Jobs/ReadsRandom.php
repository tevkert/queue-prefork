<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/** Fixture: records RNG output and generated names; every worker must produce different values. */
class ReadsRandom implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    /** @param float $hold seconds to keep the worker busy after drawing, so other jobs go to other workers */
    public function __construct(public float $hold = 0.0)
    {
    }

    public function handle(): void
    {
        $temp = tempnam(sys_get_temp_dir(), 'wb');

        JobResult::record('ReadsRandom', [
            'mt_rand' => mt_rand(),
            'rand' => rand(),
            'random_int' => random_int(0, PHP_INT_MAX),
            'str_random' => Str::random(16),
            'uuid' => (string) Str::uuid(),
            'uniqid' => uniqid(),
            'tempnam' => basename($temp),
        ]);

        @unlink($temp);
        usleep((int) ($this->hold * 1e6));
    }
}
