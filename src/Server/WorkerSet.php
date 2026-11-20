<?php

namespace Tevkert\QueuePrefork\Server;

use Closure;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Process;
use Throwable;

/**
 * The `horizon:work` workers one server forked: a unit's fork server for its supervisor loop, or a template
 * for a master that runs its supervisors in its own process. Every event goes to the client through $send.
 */
final class WorkerSet
{
    /** @var array<string, array{pid: int, start: ?string, channel: ?Channel, admitted: bool, ready: bool}> */
    private array $workers = [];

    /** @var array<int, string> */
    private array $byPid = [];

    /** @param Closure(array): void $send */
    public function __construct(private Closure $send) {}

    public function has(string $id): bool
    {
        return isset($this->workers[$id]);
    }

    /**
     * Forks the worker a `spawn` asks for. Returns the child's job in the child, null in this server.
     *
     * @param  list<int>  $block  signals held while forking, so none is handled with a half-recorded child
     * @param  list<resource|Channel|null>  $close  this server's own channel ends, which the worker must not keep
     * @param  string|null  $refuse  a reason to answer spawn_failed instead of forking
     */
    public function fork(array $message, string $defaultCwd, array $block, array $close, ?string $refuse = null): ?ChildJob
    {
        $id = (string) $message['id'];

        try {
            $command = CommandLine::parse((string) $message['cmd'], ['horizon:work']);
        } catch (Throwable $e) {
            ($this->send)(['t' => 'spawn_failed', 'id' => $id, 'reason' => $e->getMessage()]);

            return null;
        }

        if ($refuse !== null) {
            ($this->send)(['t' => 'spawn_failed', 'id' => $id, 'reason' => $refuse]);

            return null;
        }

        $cwd = (string) ($message['cwd'] ?? $defaultCwd);
        $env = is_array($message['env'] ?? null) ? $message['env'] : [];
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            ($this->send)(['t' => 'spawn_failed', 'id' => $id, 'reason' => 'socket pair']);

            return null;
        }

        pcntl_sigprocmask(SIG_BLOCK, $block, $previous);

        try {
            $pid = Process::fork();
        } catch (Throwable) {
            $pid = -1;
        }

        if ($pid === 0) {
            @fclose($pair[0]);

            foreach ($this->workers as $worker) {
                $close[] = $worker['channel'];
            }

            $prepend = is_string($message['prepend'] ?? null) ? $message['prepend'] : null;

            return ChildRoutine::become('worker', $pair[1], $close, $command, $cwd, $env, $id, prepend: $prepend);
        }

        @fclose($pair[1]);

        if ($pid < 0) {
            @fclose($pair[0]);
            pcntl_sigprocmask(SIG_SETMASK, $previous);
            ($this->send)(['t' => 'spawn_failed', 'id' => $id, 'reason' => 'fork failed']);

            return null;
        }

        $start = Identity::startTime($pid);
        $this->workers[$id] = [
            'pid' => $pid, 'start' => $start, 'channel' => new Channel($pair[0]), 'admitted' => false, 'ready' => false,
        ];
        $this->byPid[$pid] = $id;
        pcntl_sigprocmask(SIG_SETMASK, $previous);
        ($this->send)(['t' => 'spawned', 'id' => $id, 'pid' => $pid, 'start' => $start]);

        return null;
    }

    public function admit(string $id, bool $paused): void
    {
        if (isset($this->workers[$id]) && $this->workers[$id]['channel'] !== null) {
            $this->workers[$id]['admitted'] = true;
            $this->workers[$id]['channel']->send(['t' => 'admit', 'paused' => $paused]);
        }
    }

    /** Before its first loop a worker has no signal handlers: a pause reaches it on its startup channel. */
    public function pauseState(string $id, bool $paused): void
    {
        if (isset($this->workers[$id]) && $this->workers[$id]['channel'] !== null && ! $this->workers[$id]['ready']) {
            $this->workers[$id]['channel']->send(['t' => 'pause_state', 'paused' => $paused]);
        }
    }

    /** A worker not admitted yet has run no app code: nothing to let finish. */
    public function cancel(string $id): void
    {
        if (isset($this->workers[$id]) && ! $this->workers[$id]['admitted']) {
            posix_kill($this->workers[$id]['pid'], SIGKILL);
        }
    }

    public function signal(string $id, int $signal): bool
    {
        return isset($this->workers[$id]) && posix_kill($this->workers[$id]['pid'], $signal);
    }

    /** A worker's startup channel carries only its `ready`; it is closed as soon as that arrived. */
    public function readReady(): void
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker['channel'] === null) {
                continue;
            }

            foreach ($worker['channel']->receive() as $message) {
                if (($message['t'] ?? null) === 'ready') {
                    $this->workers[$id]['ready'] = true;
                    ($this->send)(['t' => 'ready', 'id' => $id]);
                }
            }

            $worker['channel']->flush();

            if ($this->workers[$id]['ready'] || $worker['channel']->eof) {
                $worker['channel']->close();
                $this->workers[$id]['channel'] = null;
            }
        }
    }

    /** @return list<Channel> the startup channels still open, for the server's select */
    public function channels(): array
    {
        $channels = [];

        foreach ($this->workers as $worker) {
            if ($worker['channel'] !== null && $worker['channel']->isOpen()) {
                $channels[] = $worker['channel'];
            }
        }

        return $channels;
    }

    /** Whether $pid was one of these workers; if so, its exit has been reported. */
    public function reaped(int $pid, int $status): bool
    {
        $id = $this->byPid[$pid] ?? null;

        if ($id === null) {
            return false;
        }

        unset($this->byPid[$pid]);
        $worker = $this->workers[$id];
        unset($this->workers[$id]);
        $worker['channel']?->close();
        ($this->send)([
            't' => 'exited', 'id' => $id, 'pid' => $pid, 'start' => $worker['start'], 'status' => $status, 'ready' => $worker['ready'],
        ]);

        return true;
    }

    /** @return list<int> */
    public function pids(): array
    {
        return array_keys($this->byPid);
    }
}
