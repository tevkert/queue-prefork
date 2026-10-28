<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Laravel\Horizon\SupervisorOptions;
use Tevkert\QueuePrefork\Master;

/** Creates each supervisor as a forked unit when the template can, or as Horizon's stock process. */
class PreforkAddSupervisor extends AddSupervisor
{
    protected function createProcess(MasterSupervisor $master, SupervisorOptions $options)
    {
        return app(Master::class)->unitProcess($options) ?? parent::createProcess($master, $options);
    }
}
