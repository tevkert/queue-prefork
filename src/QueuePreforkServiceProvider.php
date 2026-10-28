<?php

namespace Tevkert\QueuePrefork;

use Illuminate\Support\ServiceProvider;

/**
 * Config, publishing and commands. The process-tree wiring itself doesn't need this provider: it comes
 * from src/hook.php, so slim boots with fixed provider lists work too.
 */
class QueuePreforkServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-prefork.php', 'queue-prefork');
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([__DIR__.'/../config/queue-prefork.php' => config_path('queue-prefork.php')], 'queue-prefork-config');

        $this->commands([
            Console\SwitchCommand::class,
            Console\StatusCommand::class,
            Console\DoctorCommand::class,
            Console\RetireCommand::class,
        ]);
    }
}
