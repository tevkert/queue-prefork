<?php

namespace App\Horizon;

use Laravel\Horizon\Console\HorizonCommand;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\ProvisioningPlan;
use Symfony\Component\Console\Attribute\AsCommand;
use Tevkert\QueuePrefork\Master;

/**
 * Horizon's command, with the package's master started before the plan deploys, as the README's
 * "Supervisors inside the master" asks of an app's own horizon command.
 */
#[AsCommand(name: 'horizon')]
class InlineHorizonCommand extends HorizonCommand
{
    public function handle(MasterSupervisorRepository $masters)
    {
        if ($masters->find(MasterSupervisor::name())) {
            return $this->components->warn('A master supervisor is already running on this machine.');
        }

        $environment = $this->option('environment') ?? config('horizon.env') ?? config('app.env');

        $master = (new MasterSupervisor($environment))->handleOutputUsing(function ($type, $line) {
            $this->output->write($line);
        });

        // Bound only for `artisan horizon` while the package is on; start() stays stock when a check fails.
        if ($this->laravel->bound(Master::class)) {
            $this->laravel->make(Master::class)->start(warn: fn (string $line) => $this->components->warn($line));
        }

        ProvisioningPlan::get(MasterSupervisor::name())->deploy($environment);

        $this->components->info('Horizon started successfully, supervisors inside the master.');

        pcntl_async_signals(true);

        pcntl_signal(SIGINT, function () use ($master) {
            $this->output->writeln('');
            $this->components->info('Shutting down.');

            return $master->terminate();
        });

        $master->monitor();
    }
}
