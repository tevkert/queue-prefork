<?php

namespace Tevkert\QueuePrefork\Horizon;

use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use Laravel\Horizon\WorkerProcess;
use Tevkert\QueuePrefork\Client\Direct;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Client\Report;
use Tevkert\QueuePrefork\Link;
use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Server\RoleGuard;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\StatusFiles;

/**
 * Horizon's Supervisor, running as a forked unit's loop. Everything is stock except: the pools fork
 * workers through the fork server; the recorded pid is the fork server's; signals are "armed" only once
 * Horizon installed its handlers; the parent check watches the fork server rather than the master; and
 * endUnit() ends a unit that lost its fork server without waiting for long jobs and without its
 * handles killing them.
 */
class PreforkSupervisor extends Supervisor
{
    private bool $noWait = false;

    public function __construct(SupervisorOptions $options)
    {
        parent::__construct($options);
        Link::$supervisor = $this;
    }

    protected function createProcessPool(SupervisorOptions $options)
    {
        return new PreforkProcessPool($options, function ($type, $line) {
            $this->output($type, $line);
        });
    }

    public function pid()
    {
        return Runtime::$serverPid ?? getmypid();
    }

    protected function listenForSignals()
    {
        parent::listenForSignals();
        Link::armed();
    }

    protected function ensureParentIsRunning()
    {
        if (Runtime::$serverPid === null) {
            parent::ensureParentIsRunning();

            return;
        }

        if (Identity::alive(Runtime::$serverPid, Runtime::$serverStart)) {
            return;
        }

        if (Identity::alive((int) Runtime::$masterPid, Runtime::$masterStart)) {
            // As on EOF: worker signals go direct from here (nothing would read the channel), and the
            // broker's onLost ends the unit.
            Link::broker()->markLost();

            return;
        }

        $this->terminate();
    }

    protected function shouldWait()
    {
        return $this->noWait ? false : parent::shouldWait();
    }

    public function endUnit(string $reason): void
    {
        Report::event('UnitEnded', ['supervisor' => $this->name, 'reason' => $reason]);

        foreach ($this->processPools as $pool) {
            foreach ($pool->processes() as $process) {
                $this->release($process);
            }

            foreach ($pool->terminatingProcesses as $terminating) {
                $this->release($terminating['process']);
            }
        }

        $this->noWait = true;
        $this->terminate(1);
    }

    protected function exitProcess($status = 0)
    {
        if (! Link::broker()->drain(2.0)) {
            // The fork server didn't take the queued TERMs: deliver them directly. While it lives, only to
            // its own (unreaped) children, which can't be a reused pid; once it's gone, best effort.
            $serverAlive = Runtime::$serverPid !== null && Identity::alive(Runtime::$serverPid, Runtime::$serverStart);

            foreach (Link::broker()->handles() as $handle) {
                if ($handle->parentSignalled && $handle->state() === 'running') {
                    Direct::kill((int) $handle->getPid(), $handle->childStart(), SIGTERM, $serverAlive ? Runtime::$serverPid : null);
                }
            }
        }

        if (Runtime::$statusDir !== null && Runtime::$unitId !== null) {
            StatusFiles::writeIntent(Runtime::$statusDir, Runtime::$unitId, (int) $status);
        }

        RoleGuard::armWatchdog();

        exit((int) $status);
    }

    private function release(WorkerProcess $process): void
    {
        if ($process->process instanceof ForkedProcess) {
            $process->process->disarm();
            $process->process->cancelIfPending();
        }
    }
}
