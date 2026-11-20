<?php

namespace App\Horizon;

use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Laravel\Horizon\SupervisorOptions;

/** Adds each supervisor to the master's own process instead of starting a horizon:supervisor process. */
class AddInlineSupervisor extends AddSupervisor
{
    public function process(MasterSupervisor $master, array $options)
    {
        $options = SupervisorOptions::fromArray($options);
        $supervisor = new InlineSupervisor($options);

        $supervisor->handleOutputUsing(fn ($type, $line) => $master->output($type, $line));

        // horizon:supervisor starts with the same count before it first balances.
        $supervisor->scale((int) floor(($options->minProcesses + $options->maxProcesses) / 2));
        $supervisor->persist();

        $master->supervisors->push($supervisor);
    }
}
