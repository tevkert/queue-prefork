<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

/**
 * Fixture: runs an Artisan command, then keeps working for $seconds. Records `CallsArtisan.called`
 * after the command and `CallsArtisan` after the wait, each with the type of the SIGALRM handler.
 * Waits in a loop like TimesOut, because a signal cuts sleep() short.
 */
class CallsArtisan implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public int $seconds = 18)
    {
    }

    public function handle(): void
    {
        $alarm = fn (): string => get_debug_type(pcntl_signal_get_handler(SIGALRM));
        $before = $alarm();

        Artisan::call('env');

        $started = microtime(true);
        JobResult::record('CallsArtisan.called', ['alarm_before' => $before, 'alarm_after' => $alarm()]);

        while (($left = $started + $this->seconds - microtime(true)) > 0) {
            usleep((int) (min($left, 0.5) * 1_000_000));
        }

        JobResult::record('CallsArtisan', ['alarm_before' => $before, 'alarm_after' => $alarm(), 'seconds' => $this->seconds]);
    }
}
