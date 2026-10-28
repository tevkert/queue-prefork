<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\MasterHandoff;
use Tevkert\QueuePrefork\Preflight;
use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Audit;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Process;
use Tevkert\QueuePrefork\Support\TemplateKey;
use Throwable;

/**
 * The Horizon template process: loads no application code, holds opcache's shared memory, and forks a
 * fork server per supervisor on request.
 *
 * It is either spawned by the Horizon master as its child, or, in host mode, the very process the
 * process manager started: `artisan horizon` exec'd into it before booting (MasterHandoff). A host
 * forks the master itself, relays the process manager's signals to it, and exits as the master does.
 *
 * main() returns only in forked children (the master, supervisor loops and workers), with the job to
 * run at the launcher's top level. The template and its fork servers always end through RoleGuard.
 */
final class Template
{
    /** Signals a host relays to its master, as the master's own pid would have received them. */
    private const RELAY = [SIGHUP, SIGINT, SIGQUIT, SIGUSR1, SIGUSR2, SIGTERM, SIGCONT];

    /** Signal deaths a host re-raises on itself (others become exit 128+n, as in bin/stub.sh). */
    private const RERAISE = [SIGHUP, SIGINT, SIGKILL, SIGUSR1, SIGUSR2, SIGPIPE, SIGALRM, SIGTERM];

    /** Signals that stop a master: once one arrived, a host never starts the stock master in its place. */
    private const STOPS = [SIGHUP, SIGINT, SIGQUIT, SIGTERM];

    private Channel $control;

    /** Host mode: the master this template forked, and the command line it would exec to run stock. */
    private ?int $masterPid = null;

    /** @var list<string> */
    private array $masterCommand = [];

    /** Host mode: the signal mask the program started with, and the relayed signals it ignores (nohup). */
    private array $mask = [];

    /** @var list<int> */
    private array $ignored = [];

    private bool $configured = false;

    private ?string $stockAfterMaster = null;

    private bool $stopRequested = false;

    /** @var array<string, array{pid: int, start: ?string, channel: Channel, loopPid: ?int, loopStart: ?string}> */
    private array $units = [];

    /** @var array<int, string> pid => unit id */
    private array $byPid = [];

    private float $lastOpcacheReport;

    public static function main(): ChildJob
    {
        $job = null;
        $control = null;

        try {
            Runtime::$role = 'template';
            RoleGuard::install();
            Runtime::preload(); // what it forks inherits them: the master, fork servers, loops and workers

            if (getenv('QP_SERVER') === 'master') {
                return $job = self::host();
            }

            self::loadEnvironment();
            @cli_set_process_title('php [hp-template:'.(getenv('HP_GENERATION') ?: '0').']');
            $stream = @fopen('php://fd/3', 'r+');

            if ($stream === false) {
                RoleGuard::exitServer(78);
            }

            $control = new Channel($stream);
            $reasons = self::audit(Audit::fdsSharing(3));

            if ($reasons !== [] && PHP_OS_FAMILY === 'Linux') {
                $control->send(['t' => 'unsafe', 'reasons' => $reasons]);
                $control->flushBlocking(1.0);
                RoleGuard::exitServer(78);
            }

            $control->send(self::ready(getmypid(), Identity::startTime(getmypid()), $reasons));

            return $job = (new self($control))->serve();
        } catch (Throwable $e) {
            if (Runtime::isChild()) {
                @fwrite(STDERR, 'queue-prefork child setup failed: '.$e->getMessage()."\n");
                RoleGuard::shim(1);
            }

            try {
                $control?->send(['t' => 'log', 'level' => 'error', 'message' => get_class($e).': '.$e->getMessage()]);
                $control?->flushBlocking(1.0);
            } catch (Throwable) {
            }

            RoleGuard::exitServer(70);
        } finally {
            if ($job === null && Runtime::isServer()) {
                RoleGuard::exitServer(70);
            }
        }
    }

    /** Host mode: check this process, fork and admit the master, then serve it. */
    private static function host(): ChildJob
    {
        // Until serve() relays them, a signal must neither kill this host with its master admitted nor be lost.
        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD, ...self::RELAY], $mask);
        @cli_set_process_title('php [hp-template:host]');

        $host = new self;
        $host->mask = $mask;
        $host->ignored = self::ignoredSignals();
        $host->masterCommand = self::masterCommand();
        Runtime::$config = self::hostConfig();

        // A max_execution_time from the ini would end this long-lived process: keep it for the master.
        $timeLimit = function_exists('set_time_limit') ? (int) ini_get('max_execution_time') : 0;

        if ($timeLimit > 0) {
            set_time_limit(0);
        }

        $reasons = self::hostReasons();

        if ($reasons !== []) {
            $host->execStock('unsafe template: '.implode('; ', $reasons));
        }

        usleep((int) (Opcache::freshCacheWait(dirname((string) getenv('QP_MASTER_ENTRY'))) * 1e6));

        try {
            $master = CommandLine::fromProcess($host->masterCommand, ['horizon']);
        } catch (Throwable $e) {
            $host->execStock($e->getMessage());
        }

        return $host->forkMaster($master, $timeLimit);
    }

    /**
     * The relayed signals the program ignores (nohup, a background job), which the exec into this
     * template reset. The master starts with them ignored, as a stock master would, and the stock exec
     * keeps them ignored; they are relayed all the same, for the handlers Horizon installs.
     *
     * @return list<int>
     */
    private static function ignoredSignals(): array
    {
        $ignored = array_map('intval', explode(',', (string) getenv('QP_IGNORED')));

        return array_values(array_intersect(self::RELAY, $ignored));
    }

    /** @return list<string> the master's command line, as the kernel had it */
    private static function masterCommand(): array
    {
        $command = json_decode((string) getenv('QP_MASTER_COMMAND'), true);

        if (! is_array($command) || count($command) < 3) {
            RoleGuard::exitServer(78); // no command line to run, not even stock
        }

        return array_values(array_map('strval', $command));
    }

    private static function hostConfig(): array
    {
        $settings = (array) (json_decode((string) getenv('QP_SETTINGS'), true) ?: []);

        $allowed = (string) ($settings['QUEUE_PREFORK_ALLOW_DESCRIPTORS'] ?? '');

        return [
            'thread_audit' => ($settings['QUEUE_PREFORK_THREAD_AUDIT'] ?? '1') !== '0',
            'allow_descriptors' => array_values(array_filter(explode(',', $allowed))),
        ];
    }

    /**
     * Why this host can't fork the master: its ini isn't the command's, or a fork of it wouldn't be
     * safe (opcache, threads, descriptors, extensions). Nothing at fd 3 is the package's here.
     *
     * @return list<string>
     */
    private static function hostReasons(): array
    {
        $theirs = json_decode((string) @file_get_contents((string) getenv('QP_INI')), true);
        $reasons = [];

        if (! is_array($theirs)) {
            $reasons[] = 'the master\'s ini snapshot is missing';
        } elseif ($theirs !== TemplateKey::iniValues()) {
            $reasons[] = 'its ini differs from the master\'s: '.implode(', ', TemplateKey::iniDifferences($theirs));
        }

        return [...$reasons, ...self::audit([]), ...Preflight::extensions([])];
    }

    /** Forks the master, admits it, and serves it and the units it asks for. */
    private function forkMaster(CommandLine $master, int $timeLimit): ChildJob
    {
        $host = getmypid();
        $hostStart = Identity::startTime($host);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $pid = $pair === false ? -1 : Process::fork();

        if ($pid === 0) {
            @fclose($pair[0]);
            Runtime::$hostPid = $host;
            Runtime::$hostStart = $hostStart;
            $rearm = $timeLimit > 0 ? static fn () => set_time_limit($timeLimit) : null;

            return ChildRoutine::become(
                'horizon-master', $pair[1], [], $master, (string) getcwd(), self::stockEnv(), 'master', $rearm,
                entry: (string) getenv('QP_MASTER_ENTRY'), ignore: $this->ignored,
            );
        }

        if ($pid < 0) {
            $this->execStock('could not fork the master');
        }

        @fclose($pair[1]);
        $this->control = new Channel($pair[0]);
        $this->control->send(['t' => 'admit', 'paused' => false]);
        $this->control->send(self::ready($host, $hostStart, [], host: true));
        $this->masterPid = Runtime::$masterPid = $pid;
        Runtime::$masterStart = Identity::startTime($pid);

        return $this->serve();
    }

    /**
     * Becomes the stock master in this same process: the original command, with the handoff off, the
     * program's signal mask, and its ignored signals still ignored.
     */
    private function execStock(string $why): never
    {
        @fwrite(STDERR, '[queue-prefork] the Horizon master runs as before: '.$why."\n");

        foreach ($this->ignored as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }

        pcntl_sigprocmask(SIG_SETMASK, $this->mask); // a stop pending until now ends this process here
        @pcntl_exec(PHP_BINARY, array_slice($this->masterCommand, 1), ['QUEUE_PREFORK_HANDOFF' => '0'] + self::stockEnv());
        RoleGuard::exitServer(70);
    }

    /** @return array<string, string> the program's own environment, without what the handoff added */
    private static function stockEnv(): array
    {
        return array_diff_key(getenv(), array_flip(MasterHandoff::ENV));
    }

    private static function ready(int $pid, ?string $start, array $warnings, bool $host = false): array
    {
        return [
            't' => 'ready',
            'pid' => $pid,
            'start' => $start,
            'opcache' => Opcache::report(),
            'threads' => Audit::threadCount(),
            'warnings' => $warnings,
            'protocol' => Runtime::PROTOCOL,
            ...($host ? ['host' => true] : []),
        ];
    }

    private static function loadEnvironment(): void
    {
        Runtime::$masterPid = (int) getenv('HP_MASTER_PID');
        Runtime::$masterStart = getenv('HP_MASTER_START') ?: null;
        Runtime::$runtimeDir = getenv('HP_RUNTIME_DIR') ?: null;
        Runtime::$statusDir = getenv('HP_STATUS_DIR') ?: null;
        Runtime::$config = json_decode((string) getenv('HP_CONFIG'), true) ?: [];
    }

    /**
     * @param  list<int>  $owned  the package's own descriptors
     * @return list<string>
     */
    private static function audit(array $owned): array
    {
        $reasons = [];

        if (! Opcache::enabled()) {
            $reasons[] = 'opcache is not active in the template';
        }

        if (Runtime::config('thread_audit', true)) {
            $reasons = array_merge($reasons, Audit::threads());
        }

        return array_merge($reasons, Audit::descriptors($owned, Runtime::config('allow_descriptors', [])));
    }

    private function __construct(?Channel $control = null)
    {
        if ($control !== null) {
            $this->control = $control;
        }

        $this->lastOpcacheReport = microtime(true);
    }

    private function hosting(): bool
    {
        return $this->masterPid !== null;
    }

    private function serve(): ChildJob
    {
        if ($this->hosting()) {
            Signals::install([SIGCHLD, ...self::RELAY], []);
            pcntl_sigprocmask(SIG_SETMASK, $this->mask); // what arrived since host() started is relayed now
        } else {
            Signals::install([SIGCHLD], [SIGTERM, SIGINT, SIGHUP, SIGQUIT, SIGUSR1, SIGUSR2]);
        }

        while (true) {
            $this->relaySignals();
            $this->waitForStreams();

            foreach ($this->control->receive() as $message) {
                $job = $this->onControl($message);

                if ($job !== null) {
                    return $job;
                }
            }

            foreach ($this->units as $id => $unit) {
                foreach ($unit['channel']->receive() as $message) {
                    $this->onUnitMessage($id, $message);
                }

                $unit['channel']->flush();
            }

            $this->control->flush();
            $this->reap();

            // A host ends when it reaps its master (reap()); a spawned template when its master is gone.
            if (! $this->hosting() && ($this->control->eof || ! $this->masterAlive())) {
                RoleGuard::exitServer(0);
            }

            $this->reportOpcache();
        }
    }

    private function masterAlive(): bool
    {
        return Identity::alive((int) Runtime::$masterPid, Runtime::$masterStart);
    }

    private function relaySignals(): void
    {
        foreach (Signals::take() as $signal => $count) {
            if ($this->hosting() && $signal !== SIGCHLD) {
                $this->stopRequested = $this->stopRequested || in_array($signal, $this->stops(), true);
                posix_kill($this->masterPid, $signal); // still our unreaped child: the pid is its own
            }
        }
    }

    private function waitForStreams(): void
    {
        // A host's master may close its channel before it exits: stop reading it, keep reaping.
        $read = $this->control->eof ? [] : [$this->control->stream()];
        $write = ! $this->control->eof && $this->control->wantsWrite() ? [$this->control->stream()] : [];

        foreach ($this->units as $unit) {
            if ($unit['channel']->isOpen()) {
                $read[] = $unit['channel']->stream();

                if ($unit['channel']->wantsWrite()) {
                    $write[] = $unit['channel']->stream();
                }
            }
        }

        if ($read === [] && $write === []) {
            usleep(200000);

            return;
        }

        $except = null;
        $write = $write === [] ? null : $write;
        @stream_select($read, $write, $except, 1);
    }

    private function reportOpcache(): void
    {
        // A host reports only to a master that attached (sent config): another one wouldn't read it.
        if ($this->hosting() && ! $this->configured || $this->control->eof || microtime(true) - $this->lastOpcacheReport < 60) {
            return;
        }

        $this->lastOpcacheReport = microtime(true);
        $this->control->send(['t' => 'opcache', 'report' => Opcache::report()]);
    }

    private function onControl(array $message): ?ChildJob
    {
        $id = (string) ($message['id'] ?? '');

        switch ($message['t'] ?? null) {
            case 'spawn':
                return $this->forkUnit($message);

            case 'admit':
            case 'cancel':
                if (isset($this->units[$id])) {
                    $this->units[$id]['channel']->send(['t' => $message['t'], 'id' => $id]);
                }
                break;

            case 'signal':
                $delivered = $this->signalUnit($id, (int) $message['sig'], ($message['target'] ?? 'one') === 'both');
                $this->control->send(['t' => 'signalled', 'id' => $id, 'delivered' => $delivered]);
                break;

            case 'ping':
                $this->control->send(['t' => 'pong', 'id' => $id]);
                break;

            case 'config':
                $this->configure($message);
                break;

            case 'stock':
                // Host mode: the master is off after boot; run the stock master once it's gone.
                if ($this->hosting()) {
                    $this->stockAfterMaster = (string) ($message['reason'] ?? 'switched off');
                }
                break;
        }

        return null;
    }

    private function signalUnit(string $id, int $signal, bool $andLoop): bool
    {
        if (! isset($this->units[$id])) {
            return false;
        }

        $unit = $this->units[$id];
        $delivered = posix_kill($unit['pid'], $signal);

        if ($andLoop && $unit['loopPid'] !== null && Identity::alive($unit['loopPid'], $unit['loopStart'])) {
            posix_kill($unit['loopPid'], $signal);
        }

        return $delivered;
    }

    /** Host mode: the master's settings and status dir, sent before its first spawn. */
    private function configure(array $message): void
    {
        if (! $this->hosting()) {
            return;
        }

        Runtime::$config = array_merge(Runtime::$config, (array) ($message['config'] ?? []));
        Runtime::$statusDir = is_string($message['status_dir'] ?? null) ? $message['status_dir'] : null;
        Runtime::$runtimeDir = Runtime::$statusDir !== null ? dirname(Runtime::$statusDir) : null;
        $this->configured = true;
    }

    private function forkUnit(array $message): ?ChildJob
    {
        $id = (string) $message['id'];

        try {
            $command = CommandLine::parse((string) $message['cmd'], ['horizon:supervisor']);
        } catch (Throwable $e) {
            $this->control->send(['t' => 'spawn_failed', 'id' => $id, 'reason' => $e->getMessage()]);

            return null;
        }

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            $this->control->send(['t' => 'spawn_failed', 'id' => $id, 'reason' => 'socket pair']);

            return null;
        }

        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous);

        try {
            $pid = Process::fork();
        } catch (Throwable) {
            $pid = -1;
        }

        if ($pid === 0) {
            Runtime::$role = 'fork-server';
            Signals::take(); // the signals the template caught are its own to relay
            @fclose($pair[0]);
            $this->control->close();

            foreach ($this->units as $unit) {
                $unit['channel']->close();
            }

            pcntl_sigprocmask(SIG_SETMASK, $previous);

            return (new UnitServer(new Channel($pair[1]), $message, $command))->serve();
        }

        @fclose($pair[1]);

        if ($pid < 0) {
            @fclose($pair[0]);
            pcntl_sigprocmask(SIG_SETMASK, $previous);
            $this->control->send(['t' => 'spawn_failed', 'id' => $id, 'reason' => 'fork failed']);

            return null;
        }

        $this->units[$id] = [
            'pid' => $pid,
            'start' => Identity::startTime($pid),
            'channel' => new Channel($pair[0]),
            'loopPid' => null,
            'loopStart' => null,
        ];
        $this->byPid[$pid] = $id;
        pcntl_sigprocmask(SIG_SETMASK, $previous);

        return null;
    }

    private function onUnitMessage(string $id, array $message): void
    {
        switch ($message['t'] ?? null) {
            case 'spawned':
                $this->units[$id]['loopPid'] = (int) $message['loop_pid'];
                $this->units[$id]['loopStart'] = $message['loop_start'] ?? null;
                $this->control->send([
                    't' => 'spawned',
                    'id' => $id,
                    'pid' => $this->units[$id]['pid'],
                    'start' => $this->units[$id]['start'],
                    'loop_pid' => (int) $message['loop_pid'],
                    'loop_start' => $message['loop_start'] ?? null,
                ]);
                break;

            case 'log':
                $this->control->send($message + ['unit' => $id]);
                break;
        }
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $raw, WNOHANG)) > 0) {
            if ($pid === $this->masterPid) {
                $this->masterExited($raw); // its units drain on their own, as under a stock master
            }

            $id = $this->byPid[$pid] ?? null;
            unset($this->byPid[$pid]);

            if ($id === null) {
                continue;
            }

            $unit = $this->units[$id];
            unset($this->units[$id]);
            $unit['channel']->close();
            $this->control->send([
                't' => 'exited', 'id' => $id, 'pid' => $pid, 'start' => $unit['start'], 'status' => Signals::status($raw),
            ]);
        }
    }

    /**
     * Host mode: the stock master runs in this process if the master asked for it and exited cleanly,
     * and nothing asked the program to stop meanwhile; otherwise the host ends as the master did.
     */
    private function masterExited(int $raw): never
    {
        if ($this->stockAfterMaster === null || ! pcntl_wifexited($raw) || pcntl_wexitstatus($raw) !== 0) {
            self::exitLike($raw);
        }

        pcntl_sigprocmask(SIG_BLOCK, self::RELAY);
        $signals = array_keys(Signals::take());

        while (function_exists('pcntl_sigtimedwait') && ($signal = @pcntl_sigtimedwait(self::RELAY, $info, 0, 1)) > 0) {
            $signals[] = $signal;
        }

        if ($this->stopRequested || array_intersect($signals, $this->stops()) !== []) {
            self::exitLike($raw);
        }

        foreach (array_diff(self::RELAY, $this->ignored) as $signal) {
            pcntl_signal($signal, SIG_DFL); // from here until the exec, a stop ends this process
        }

        $this->execStock($this->stockAfterMaster);
    }

    /** The stop signals that would stop a stock master now: the ones the program doesn't ignore. */
    private function stops(): array
    {
        return array_values(array_diff(self::STOPS, $this->ignored));
    }

    /** Ends this host the way its master ended: the same exit code, or the same signal. */
    private static function exitLike(int $raw): never
    {
        if (pcntl_wifsignaled($raw)) {
            $signal = pcntl_wtermsig($raw);

            if (in_array($signal, self::RERAISE, true)) {
                if ($signal !== SIGKILL) {
                    pcntl_signal($signal, SIG_DFL);
                }

                pcntl_sigprocmask(SIG_UNBLOCK, [$signal]);
                posix_kill(getmypid(), $signal);
                usleep(100000);
            }

            RoleGuard::exitServer(128 + $signal);
        }

        RoleGuard::exitServer(pcntl_wexitstatus($raw));
    }
}
