<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\WorkerProcess;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Master;

/**
 * For the ProcessPool of a supervisor that runs inside the Horizon master: its workers are forked by the
 * template once the master started in inline mode (Master::start(), queue-prefork.horizon_inline), and
 * exec'd as stock otherwise.
 */
trait ForksWorkersFromTemplate
{
    protected function createProcess()
    {
        $process = $this->preforkMaster()?->workerProcess($this->options);

        return $process === null ? parent::createProcess() : new WorkerProcess($process);
    }

    public function monitor()
    {
        $this->replaceRetiredWorkers();

        parent::monitor();
    }

    /**
     * Horizon restarts a stopped worker on the handle it has. A forked handle whose template is gone, or whose
     * supervisor the crash breaker turned stock, gets a new one instead: forked again, or stock.
     */
    protected function replaceRetiredWorkers(): void
    {
        $master = $this->preforkMaster();

        foreach ($this->processes as $index => $worker) {
            $process = $worker->process;

            if (! $process instanceof ForkedProcess || $process->isRunning()) {
                continue;
            }

            if ($process->serverGone() || $master?->retires($process, $this->options->name)) {
                $this->processes[$index] = $this->createProcess()->handleOutputUsing(function ($type, $line) {
                    call_user_func($this->output, $type, $line);
                });
            }
        }
    }

    private function preforkMaster(): ?Master
    {
        $app = app();

        return $app->bound(Master::class) ? $app->make(Master::class) : null;
    }
}
