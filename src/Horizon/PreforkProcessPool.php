<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\ProcessPool;
use Laravel\Horizon\WorkerProcess;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Link;

/** Horizon's ProcessPool, with workers forked by the unit's fork server instead of exec'd. */
class PreforkProcessPool extends ProcessPool
{
    protected function createProcess()
    {
        $process = new ForkedProcess(
            Link::broker(),
            'worker',
            $this->options->toWorkerCommand(),
            $this->options->directory ?? base_path(),
            ! config('horizon.fast_termination'),
        );

        $process->onExit = [Link::class, 'workerExited'](...);
        $process->onSpawnFailed = fn () => Link::endUnit('worker spawn failed');

        return new WorkerProcess($process);
    }
}
