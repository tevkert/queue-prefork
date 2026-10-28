<?php

namespace App\Providers;

use App\Support\LifecycleTrace;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Horizon's application provider, as `horizon:install` publishes it. Every Horizon process loads it,
 * so the workbench's lifecycle trace hangs off it too.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        // Test fixture: one line per CommandStarting, with the process's pid, ppid
        // and argv, in storage/logs/lifecycle.log. Stock fires it once per process with the real
        // command; a forked child must do the same.
        if (config('workbench.trace')) {
            Event::listen(CommandStarting::class, function (CommandStarting $event) {
                LifecycleTrace::write('command_starting', ['command' => $event->command]);
            });
        }
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            return false;
        });
    }
}
