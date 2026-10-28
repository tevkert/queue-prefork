<?php

namespace Tevkert\QueuePrefork\Client;

use Closure;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;
use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\StatusFiles;
use Throwable;

/**
 * A Horizon process handle backed by a fork server instead of proc_open.
 *
 * It extends Symfony's Process so listeners that type-hint it keep working, and overrides every
 * lifecycle method. States: idle → pending → running → exited | cancelled. start() never blocks, and
 * starts a finished handle again under a new request id, as Symfony's does (Horizon restarts a worker
 * by calling start() on the same handle).
 * Signals go through the reaping server while it answers, and directly (identity-checked) once it is
 * gone or overdue. Stop-on-destruct mirrors Symfony's, acts only in the process that created the
 * handle, and can be disarmed (endUnit).
 */
class ForkedProcess extends Process
{
    /** Seconds start() waits for the server's `spawned` reply before leaving it to the next tick. */
    private const SPAWN_REPLY_WAIT = 0.05;

    private string $state = 'idle';

    private string $id;

    private ?int $childPid = null;

    private ?string $childStart = null;

    private ?int $loopPid = null;

    private ?string $loopStart = null;

    private ?int $status = null;

    private ?int $notified = null;

    private bool $direct = false;

    private bool $desiredPaused = false;

    private bool $pausedAtAdmit = false;

    private bool $isReady = false;

    /** @var list<int> */
    private array $queued = [];

    private int $ownerPid;

    public float $spawnedAt = 0.0;

    /** Seconds a unit's loop gets to end itself after its fork server died. */
    public float $reapGrace = 30.0;

    private float $reapDeadline = 0.0;

    public bool $parentSignalled = false;

    /** fn (ForkedProcess $process, int $status, bool $wasReady) */
    public ?Closure $onExit = null;

    /** fn (ForkedProcess $process, string $reason) */
    public ?Closure $onSpawnFailed = null;

    public function __construct(
        private Broker $broker,
        private string $role,
        private string $line,
        private ?string $directory,
        private bool $stopOnDestruct,
        private ?string $statusDir = null,
    ) {
        parent::__construct(['true'], $directory);
        $this->ownerPid = getmypid();
        $this->id = $broker->nextId();
        $broker->register($this->id, $this);
        $this->disableOutput();
    }

    public function id(): string
    {
        return $this->id;
    }

    public function role(): string
    {
        return $this->role;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function loopPid(): ?int
    {
        return $this->loopPid;
    }

    public function childStart(): ?string
    {
        return $this->childStart;
    }

    public function isReadyWorker(): bool
    {
        return $this->isReady;
    }

    public function start(?callable $callback = null, array $env = []): void
    {
        if ($this->state === 'exited' || $this->state === 'cancelled') {
            $this->rearm();
        } elseif ($this->state !== 'idle') {
            throw new RuntimeException('Process is already running.');
        }

        $this->spawnedAt = microtime(true);

        if ($this->broker->lost) {
            $this->fail('server lost');

            return;
        }

        $this->state = 'pending';
        $this->broker->request("spawn:{$this->id}", [
            't' => 'spawn',
            'id' => $this->id,
            'cmd' => $this->line,
            'cwd' => $this->directory,
            'env' => getenv(),
            'prepend' => Runtime::$prepend ?? (string) ini_get('auto_prepend_file'),
        ]);

        // The server replies right after its fork. Admitting the child now, instead of at the requester's
        // next loop tick (about a second away), lets it start as soon as it is forked.
        $this->broker->pumpUntil(fn (): bool => $this->state !== 'pending', self::SPAWN_REPLY_WAIT);
    }

    /** @internal called by the broker */
    public function spawned(array $message): void
    {
        if ($this->state === 'cancelled') {
            $this->broker->send(['t' => 'cancel', 'id' => $this->id]);

            return;
        }

        if ($this->state !== 'pending') {
            return;
        }

        $this->childPid = (int) $message['pid'];
        $this->childStart = $message['start'] ?? null;
        $this->loopPid = isset($message['loop_pid']) ? (int) $message['loop_pid'] : null;
        $this->loopStart = $message['loop_start'] ?? null;
        $this->state = 'running';
        $this->pausedAtAdmit = $this->desiredPaused;

        // Admission: no acknowledgement; from here on the process is tracked by identity.
        $this->broker->send(['t' => 'admit', 'id' => $this->id, 'paused' => $this->desiredPaused]);

        if ($this->role === 'unit') {
            if ($this->desiredPaused) {
                $this->queued[] = SIGUSR2;
            }

            foreach ($this->queued as $signal) {
                $this->sendSignal($signal);
            }
        }

        $this->queued = [];
    }

    /** @internal */
    public function spawnFailed(string $reason): void
    {
        $this->fail($reason);
    }

    /** @internal */
    public function exited(int $status, bool $wasReady): void
    {
        $this->notified = $status;

        if ($this->role === 'unit' && $this->loopPid !== null && Identity::alive($this->loopPid, $this->loopStart)) {
            // The fork server died with its loop alive: the unit is one, so it ends only when the loop
            // does. The loop sees its fork server gone, TERMs its workers and exits on its own (endUnit);
            // a SIGKILL is only the backstop.
            $this->state = 'reaping';
            $this->reapDeadline = microtime(true) + $this->reapGrace;

            return;
        }

        $this->settle($status, $wasReady);
    }

    /** @internal */
    public function ready(): void
    {
        $this->isReady = true;

        if ($this->desiredPaused !== $this->pausedAtAdmit) {
            $this->sendSignal($this->desiredPaused ? SIGUSR2 : SIGCONT);
        }
    }

    /** @internal */
    public function serverLost(): void
    {
        $this->direct = true;

        if ($this->state === 'pending') {
            $this->fail('server lost');
        }
    }

    public function isRunning(): bool
    {
        $this->broker->pump();

        if ($this->state === 'reaping' && $this->reapDeadline > 0 && microtime(true) >= $this->reapDeadline && $this->loopPid !== null) {
            $this->reapDeadline = 0.0;
            Report::event('LoopKilled', ['unit' => $this->line, 'loop' => $this->loopPid]);
            Direct::kill($this->loopPid, $this->loopStart, SIGKILL);
        }

        if ($this->state === 'reaping' || ($this->direct && $this->state === 'running')) {
            $alive = ($this->childPid !== null && Identity::alive($this->childPid, $this->childStart))
                || ($this->role === 'unit' && $this->loopPid !== null && Identity::alive($this->loopPid, $this->loopStart));

            if (! $alive) {
                $this->settle($this->notified ?? StatusFiles::UNKNOWN, $this->isReady);
            }
        }

        return in_array($this->state, ['pending', 'running', 'reaping'], true);
    }

    public function isStarted(): bool
    {
        return $this->state !== 'idle';
    }

    public function isTerminated(): bool
    {
        return $this->isStarted() && ! $this->isRunning();
    }

    public function getExitCode(): ?int
    {
        $this->isRunning();

        return $this->status;
    }

    public function getPid(): ?int
    {
        return $this->childPid;
    }

    public function getStatus(): string
    {
        return match (true) {
            $this->state === 'idle' => self::STATUS_READY,
            $this->isRunning() => self::STATUS_STARTED,
            default => self::STATUS_TERMINATED,
        };
    }

    public function isSuccessful(): bool
    {
        return $this->getExitCode() === 0;
    }

    public function hasBeenSignaled(): bool
    {
        return $this->status !== null && $this->status > 128;
    }

    public function getTermSignal(): int
    {
        return $this->hasBeenSignaled() ? $this->status - 128 : 0;
    }

    public function hasBeenStopped(): bool
    {
        return false;
    }

    public function getCommandLine(): string
    {
        return $this->line;
    }

    public function getStartTime(): float
    {
        return $this->spawnedAt;
    }

    public function checkTimeout(): void {}

    public function wait(?callable $callback = null): int
    {
        while ($this->isRunning()) {
            usleep(100000);
        }

        return (int) $this->status;
    }

    public function signal(int $signal): static
    {
        $terminating = in_array($signal, [SIGTERM, SIGKILL, SIGINT, SIGQUIT], true);

        if ($this->state === 'pending') {
            if ($terminating) {
                $this->cancel(128 + $signal);
            } elseif ($signal === SIGUSR2 || $signal === SIGCONT) {
                $this->desiredPaused = $signal === SIGUSR2;
            } else {
                $this->queued[] = $signal;
            }

            return $this;
        }

        if (! in_array($this->state, ['running', 'reaping'], true)) {
            return $this;
        }

        if ($terminating) {
            $this->parentSignalled = true;
        }

        // A worker's handlers exist only from its first loop ("ready"): until then, carry the pause state.
        if ($this->role === 'worker' && ! $this->isReady && ($signal === SIGUSR2 || $signal === SIGCONT)) {
            $this->desiredPaused = $signal === SIGUSR2;
            $this->broker->send(['t' => 'pause_state', 'id' => $this->id, 'paused' => $this->desiredPaused]);

            return $this;
        }

        $this->sendSignal($signal);

        return $this;
    }

    public function stop(float $timeout = 10, ?int $signal = null): ?int
    {
        if ($this->state === 'pending') {
            $this->signal(SIGTERM);

            return $this->status;
        }

        if (! $this->isRunning()) {
            return $this->status;
        }

        $this->signal(SIGTERM);
        $deadline = microtime(true) + $timeout;

        while ($this->isRunning() && microtime(true) < $deadline) {
            usleep(50000);
        }

        if ($this->isRunning()) {
            $this->signal($signal ?: SIGKILL);
        }

        return $this->status;
    }

    /** endUnit: the workers must survive this process's exit (BackgroundProcess semantics). */
    public function disarm(): void
    {
        $this->stopOnDestruct = false;
    }

    public function cancelIfPending(): void
    {
        if ($this->state === 'pending') {
            $this->cancel(143);
        }
    }

    public function __destruct()
    {
        if ($this->stopOnDestruct && getmypid() === $this->ownerPid && in_array($this->state, ['pending', 'running'], true)) {
            try {
                $this->stop(0);
            } catch (Throwable) {
            }
        }
    }

    private function sendSignal(int $signal): void
    {
        if (! $this->direct && ! $this->broker->lost) {
            $this->broker->request("signal:{$this->id}", [
                't' => 'signal',
                'id' => $this->id,
                'sig' => $signal,
                'target' => $signal === SIGKILL && $this->role === 'unit' ? 'both' : 'one',
            ]);

            return;
        }

        Direct::kill((int) $this->childPid, $this->childStart, $signal);

        if ($signal === SIGKILL && $this->role === 'unit' && $this->loopPid !== null) {
            Direct::kill($this->loopPid, $this->loopStart, SIGKILL);
        }
    }

    /** Back to idle for another run: a new request id, and nothing kept from the last child. */
    private function rearm(): void
    {
        $this->broker->forget($this->id);
        $this->id = $this->broker->nextId();
        $this->broker->register($this->id, $this);
        $this->childPid = $this->childStart = $this->loopPid = $this->loopStart = null;
        $this->status = $this->notified = null;
        $this->direct = $this->isReady = $this->pausedAtAdmit = $this->parentSignalled = false;
        $this->reapDeadline = 0.0;
        $this->queued = [];
        $this->state = 'idle';
    }

    private function cancel(int $status): void
    {
        $this->broker->send(['t' => 'cancel', 'id' => $this->id]);
        $this->broker->resolve("spawn:{$this->id}");
        $this->state = 'cancelled';
        $this->status = $status;
    }

    private function fail(string $reason): void
    {
        $this->state = 'exited';
        $this->status = 1;
        ($this->onSpawnFailed)?->__invoke($this, $reason);
    }

    private function settle(int $status, bool $wasReady): void
    {
        if ($this->role === 'unit' && $this->statusDir !== null && $this->childPid !== null) {
            $resolved = StatusFiles::resolve($this->statusDir, Identity::key($this->childPid, $this->childStart), $this->notified);

            if ($resolved['source'] !== 'final' && $resolved['source'] !== 'template') {
                Report::event('StatusUnknown', ['unit' => $this->line, 'source' => $resolved['source']]);
            }

            $status = $resolved['status'];
        }

        $this->status = $status;
        $this->state = 'exited';
        ($this->onExit)?->__invoke($this, $status, $wasReady || $this->isReady);
    }
}
