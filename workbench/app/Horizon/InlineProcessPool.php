<?php

namespace App\Horizon;

use Laravel\Horizon\ProcessPool;
use Tevkert\QueuePrefork\Horizon\ForksWorkersFromTemplate;

/** An inline supervisor's pool: the template forks its workers while the package runs, stock otherwise. */
class InlineProcessPool extends ProcessPool
{
    use ForksWorkersFromTemplate;
}
