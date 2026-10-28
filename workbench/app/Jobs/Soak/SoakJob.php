<?php

namespace App\Jobs\Soak;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Base for the soak's job mix. Every job is fully determined by ($seed, $size), so the stock and
 * prefork containers do identical work for the same `soak:dispatch --seed`.
 */
abstract class SoakJob implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $seed, public int $size)
    {
    }

    protected function random(): Randomizer
    {
        return new Randomizer(new Mt19937($this->seed));
    }
}
