<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\Console\HorizonCommand;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Tevkert\QueuePrefork\Master;

/**
 * `php artisan horizon`, unchanged except that supervisors are created through the template.
 * If preflight or the template fails, this is exactly the stock command.
 */
class PreforkHorizonCommand extends HorizonCommand
{
    public function handle(MasterSupervisorRepository $masters)
    {
        $prefork = $this->laravel->make(Master::class);

        if ($prefork->start(fn (string $line) => $this->components->warn($line))) {
            $this->laravel->bind(AddSupervisor::class, PreforkAddSupervisor::class);
            $this->laravel['events']->listen(MasterSupervisorLooped::class, fn () => $prefork->tick());
        }

        return parent::handle($masters);
    }
}
