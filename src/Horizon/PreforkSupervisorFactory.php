<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\SupervisorFactory;
use Laravel\Horizon\SupervisorOptions;

class PreforkSupervisorFactory extends SupervisorFactory
{
    public function make(SupervisorOptions $options)
    {
        return new PreforkSupervisor($options);
    }
}
