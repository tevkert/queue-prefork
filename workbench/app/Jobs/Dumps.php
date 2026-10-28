<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Fixture: writes through dump() (stdout) and to STDERR; the output must reach Horizon's log. */
class Dumps implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        $marker = 'dumps-fixture-'.getmypid().'-'.bin2hex(random_bytes(4));

        dump(['fixture' => 'Dumps', 'marker' => $marker]);
        fwrite(STDERR, "Dumps fixture stderr {$marker}\n");

        JobResult::record('Dumps', ['marker' => $marker]);
    }
}
