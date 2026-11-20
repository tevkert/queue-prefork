<?php

namespace App\Horizon;

use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;

/**
 * A supervisor that runs inside the master process (HORIZON_INLINE=true), as some apps run theirs. The
 * master's loop calls monitor() for one turn of the supervisor's loop, and it never waits or exits by itself.
 * Pausing one supervisor is not supported: horizon:pause-supervisor signals its pid, which is the master's.
 */
class InlineSupervisor extends Supervisor
{
    /** MasterSupervisor::monitorSupervisors() drops a dead supervisor. */
    public bool $dead = false;

    private bool $terminated = false;

    protected function createProcessPool(SupervisorOptions $options)
    {
        return new InlineProcessPool($options, function ($type, $line) {
            $this->output($type, $line);
        });
    }

    public function monitor()
    {
        if (! $this->terminated) {
            $this->loop();
        }

        $this->dead = $this->terminated && ! $this->isRunning();
    }

    public function isRunning(): bool
    {
        return $this->processPools->map->runningProcesses()->collapse()->isNotEmpty();
    }

    /** TERMs the workers and returns: the master waits on isRunning() and exits itself. */
    public function terminate($status = 0)
    {
        $this->working = false;
        $this->terminated = true;

        app(SupervisorRepository::class)->forget($this->name);

        $this->processPools->each(fn ($pool) => $pool->processes()->each->terminate());
    }

    /** What MasterSupervisor::restart() sends every supervisor: here, a restart of its workers. */
    public function terminateWithStatus($status)
    {
        $this->restart();
    }

    protected function ensureParentIsRunning()
    {
        // The master is this supervisor's own process.
    }
}
