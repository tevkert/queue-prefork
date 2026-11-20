<?php

namespace App\Providers;

use App\Horizon\AddInlineSupervisor;
use App\Horizon\InlineHorizonCommand;
use App\Support\LifecycleTrace;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;

/**
 * Horizon's application provider, as `horizon:install` publishes it. Every Horizon process loads it,
 * so the workbench's lifecycle trace hangs off it too.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function register(): void
    {
        parent::register();

        if (config('workbench.horizon_inline')) {
            $this->app->bind(AddSupervisor::class, AddInlineSupervisor::class);
        }
    }

    public function boot(): void
    {
        parent::boot();

        if (config('workbench.horizon_inline') && $this->app->runningInConsole()) {
            $this->commands([InlineHorizonCommand::class]);

            // A paused master skips its supervisors' loops, so their records would go stale and expire.
            Event::listen(MasterSupervisorLooped::class, function (MasterSupervisorLooped $event) {
                if (! $event->master->working) {
                    $event->master->supervisors->each->persist();
                }
            });
        }

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
