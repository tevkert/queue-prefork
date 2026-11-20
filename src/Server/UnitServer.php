<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Process;
use Tevkert\QueuePrefork\Support\StatusFiles;
use Throwable;

/**
 * A unit's fork server: the pid Horizon records as the supervisor's.
 *
 * It forks the supervisor loop right away and the workers on the loop's requests, admits them, relays
 * the supervisor signals to the loop once its handlers exist ("armed"), reaps every child, reports
 * worker exits to the loop and the loop's final status to the template, and exits with that status.
 */
final class UnitServer
{
    private const RELAY = [SIGTERM, SIGUSR1, SIGUSR2, SIGCONT];

    private string $unitId;

    private string $cwd;

    /** @var array<string, string> */
    private array $env;

    private ?string $prepend;

    private ?int $loopPid = null;

    private ?string $loopStart = null;

    private ?Channel $loop = null;

    private bool $admitted = false;

    private bool $hello = false;

    private bool $armed = false;

    private bool $masterGone = false;

    /** @var list<int> relays waiting for "armed" */
    private array $queuedRelays = [];

    private WorkerSet $workers;

    private float $helloElapsed = 0.0;

    private int $helloRounds = 0;

    private float $lastTick;

    public function __construct(
        private Channel $up,
        array $spawn,
        private CommandLine $command,
    ) {
        $this->unitId = (string) $spawn['id'];
        $this->cwd = (string) ($spawn['cwd'] ?? getcwd());
        $this->env = is_array($spawn['env'] ?? null) ? $spawn['env'] : [];
        $this->prepend = is_string($spawn['prepend'] ?? null) ? $spawn['prepend'] : null;
        $this->lastTick = microtime(true);
        $this->workers = new WorkerSet(fn (array $message) => $this->loop?->send($message));
    }

    public function serve(): ChildJob
    {
        @cli_set_process_title($this->command->title().' [fork-server]');
        ChildRoutine::applyEnv($this->env);

        if (is_dir($this->cwd)) {
            chdir($this->cwd);
        }

        Signals::install(array_merge(self::RELAY, [SIGCHLD]), [SIGINT, SIGHUP, SIGQUIT]);

        $job = $this->forkLoop();

        if ($job !== null) {
            return $job;
        }

        $nice = (int) ($this->command->option('nice') ?? 0);

        if ($nice !== 0) {
            @proc_nice($nice);
        }

        while (true) {
            $job = $this->tick();

            if ($job !== null) {
                return $job;
            }
        }
    }

    private function forkLoop(): ?ChildJob
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        pcntl_sigprocmask(SIG_BLOCK, array_merge(self::RELAY, [SIGCHLD]), $previous);
        $pid = Process::fork();

        if ($pid === 0) {
            @fclose($pair[0]);

            return ChildRoutine::become(
                'unit-loop', $pair[1], [$this->up], $this->command, $this->cwd, $this->env, $this->unitId, prepend: $this->prepend,
            );
        }

        @fclose($pair[1]);

        if ($pid < 0) {
            $this->up->send(['t' => 'log', 'level' => 'error', 'message' => 'fork of the supervisor loop failed']);
            $this->up->flushBlocking(1.0);
            RoleGuard::exitServer(70);
        }

        $this->loopPid = $pid;
        $this->loopStart = Identity::startTime($pid);
        $this->loop = new Channel($pair[0]);
        pcntl_sigprocmask(SIG_SETMASK, $previous);
        $this->up->send(['t' => 'spawned', 'loop_pid' => $pid, 'loop_start' => $this->loopStart]);

        return null;
    }

    private function tick(): ?ChildJob
    {
        $this->relaySignals();
        $this->waitForChannels();
        $this->relaySignals();

        if ($this->up->isOpen()) {
            foreach ($this->up->receive() as $message) {
                $this->onUp($message);
            }

            $this->up->flush();

            if ($this->up->eof && ! $this->admitted) {
                $this->abandon(75); // the template went away before this unit was admitted
            }
        }

        if ($this->loop !== null) {
            foreach ($this->loop->receive() as $message) {
                $job = $this->onLoop($message);

                if ($job !== null) {
                    return $job;
                }
            }

            $this->loop->flush();
        }

        $this->workers->readReady();
        $this->reap();
        $this->watchMaster();
        $this->watchHello();

        return null;
    }

    private function waitForChannels(): void
    {
        $read = [];
        $write = [];

        foreach ($this->channels() as $channel) {
            $read[] = $channel->stream();

            if ($channel->wantsWrite()) {
                $write[] = $channel->stream();
            }
        }

        if ($read === []) {
            usleep(200000);

            return;
        }

        $except = null;
        $write = $write === [] ? null : $write;
        @stream_select($read, $write, $except, 1);
    }

    /** @return list<Channel> */
    private function channels(): array
    {
        $channels = [];

        if ($this->up->isOpen()) {
            $channels[] = $this->up;
        }

        if ($this->loop !== null && $this->loop->isOpen()) {
            $channels[] = $this->loop;
        }

        return [...$channels, ...$this->workers->channels()];
    }

    private function onUp(array $message): void
    {
        switch ($message['t'] ?? null) {
            case 'admit':
                $this->admitted = true;
                $this->loop?->send(['t' => 'admit']);
                break;

            case 'cancel':
                if (! $this->admitted) {
                    $this->abandon(75);
                }
                break;
        }
    }

    private function onLoop(array $message): ?ChildJob
    {
        $id = (string) ($message['id'] ?? '');

        switch ($message['t'] ?? null) {
            case 'hello':
                $this->hello = true;
                break;

            case 'armed':
                $this->armed = true;

                foreach ($this->queuedRelays as $signal) {
                    $this->signalLoop($signal);
                }

                $this->queuedRelays = [];
                break;

            case 'spawn':
                return $this->workers->fork(
                    $message,
                    $this->cwd,
                    array_merge(self::RELAY, [SIGCHLD]),
                    [$this->up, $this->loop],
                    $this->masterGone ? 'master gone' : null,
                );

            case 'admit':
                $this->workers->admit($id, (bool) ($message['paused'] ?? false));
                break;

            case 'pause_state':
                $this->workers->pauseState($id, (bool) ($message['paused'] ?? false));
                break;

            case 'cancel':
                $this->workers->cancel($id);
                break;

            case 'signal':
                $delivered = $this->workers->signal($id, (int) $message['sig']);
                $this->loop?->send(['t' => 'signalled', 'id' => $id, 'delivered' => $delivered]);
                break;

            case 'ping':
                $this->loop?->send(['t' => 'pong', 'id' => $id]);
                break;
        }

        return null;
    }

    private function relaySignals(): void
    {
        foreach (Signals::take() as $signal => $count) {
            if ($signal === SIGCHLD) {
                continue;
            }

            if ($signal === SIGTERM || $this->armed) {
                $this->signalLoop($signal);
            } else {
                $this->queuedRelays[] = $signal;
            }
        }
    }

    private function signalLoop(int $signal): void
    {
        if ($this->loopPid !== null && Identity::alive($this->loopPid, $this->loopStart)) {
            posix_kill($this->loopPid, $signal);
        }
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $raw, WNOHANG)) > 0) {
            $status = Signals::status($raw);

            if ($pid === $this->loopPid) {
                $this->finish($status);
            }

            $this->workers->reaped($pid, $status);
        }
    }

    private function watchMaster(): void
    {
        if ($this->masterGone || Identity::alive((int) Runtime::$masterPid, Runtime::$masterStart)) {
            return;
        }

        // The master is gone: stop spawning and let the loop terminate through Horizon's own path.
        $this->masterGone = true;
        $this->signalLoop(SIGTERM);
    }

    private function watchHello(): void
    {
        $now = microtime(true);
        $gap = $now - $this->lastTick;
        $this->lastTick = $now;

        if (! $this->admitted || $this->hello) {
            return;
        }

        // A long gap means this process itself was paused: don't count it against the loop.
        if ($gap > 2.0) {
            $this->helloRounds = 0;

            return;
        }

        $this->helloElapsed += $gap;
        $this->helloRounds++;

        if ($this->helloElapsed > (float) Runtime::config('hello_timeout', 15) && $this->helloRounds >= 5) {
            foreach (self::childrenOf((int) $this->loopPid) as $grandchild) {
                posix_kill($grandchild, SIGKILL);
            }

            $this->abandon(71);
        }
    }

    /** Ends the unit without a running loop: kill the loop, record the status, exit. */
    private function abandon(int $status): never
    {
        if ($this->loopPid !== null) {
            posix_kill($this->loopPid, SIGKILL);
            pcntl_waitpid($this->loopPid, $raw);
        }

        $this->finish($status);
    }

    private function finish(int $status): never
    {
        if (Runtime::$statusDir !== null) {
            StatusFiles::writeFinal(Runtime::$statusDir, Identity::key(getmypid(), Identity::startTime(getmypid())), $status);
        }

        $this->loop?->close();
        $this->up->flushBlocking(0.5);
        RoleGuard::exitServer($status);
    }

    /** @return list<int> */
    private static function childrenOf(int $pid): array
    {
        if ($pid <= 0) {
            return [];
        }

        $out = (string) @shell_exec('pgrep -P '.$pid.' 2>/dev/null');

        return array_values(array_map('intval', array_filter(preg_split('/\s+/', trim($out)) ?: [], 'ctype_digit')));
    }
}
