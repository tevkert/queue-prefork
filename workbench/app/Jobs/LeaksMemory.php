<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fixture: keeps $megabytes of memory alive in a static after the job ends, as a leaking job would.
 * Above the supervisor's `memory` (128 MB) the worker exits after the job and Horizon replaces it.
 */
class LeaksMemory implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    /** @var list<string> */
    public static array $leaked = [];

    public function __construct(public int $megabytes = 160)
    {
    }

    public function handle(): void
    {
        $before = memory_get_usage(true);

        for ($i = 0; $i < $this->megabytes; $i++) {
            // Distinct strings, so nothing is shared or interned.
            self::$leaked[] = str_repeat(chr(65 + $i % 26), 1024 * 1024 - 64).$i;
        }

        JobResult::record('LeaksMemory', [
            'megabytes' => $this->megabytes,
            'before' => $before,
            'after' => memory_get_usage(true),
            'chunks' => count(self::$leaked),
        ]);
    }
}
