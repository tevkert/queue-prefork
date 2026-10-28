<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Writes one row to `job_results` (id, job, pid, argv, value, created_at) so tests can assert on what
 * a fixture job saw and did. `argv` is always the worker's $_SERVER['argv'].
 */
class JobResult
{
    public static function record(string $job, mixed $value = null): void
    {
        DB::table('job_results')->insert([
            'job' => $job,
            'pid' => getmypid(),
            'argv' => json_encode($_SERVER['argv'] ?? null, JSON_UNESCAPED_SLASHES),
            'value' => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'created_at' => now()->format('Y-m-d H:i:s.u'),
        ]);
    }
}
