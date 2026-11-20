<?php

namespace Tevkert\QueuePrefork\Tests\Fixtures;

use Laravel\Horizon\ProcessPool;
use Tevkert\QueuePrefork\Horizon\ForksWorkersFromTemplate;

/** An app's pool for supervisors inside the master, as the README sets it up. */
class InlinePool extends ProcessPool
{
    use ForksWorkersFromTemplate;
}
