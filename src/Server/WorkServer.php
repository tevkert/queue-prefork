<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Preflight;
use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Audit;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Fd;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Native;
use Tevkert\QueuePrefork\Support\Process;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\TemplateKey;
use Throwable;

/**
 * The shared template for `queue:work`: started on demand by the first stub of a
 * key, detached, it forks one worker per stub over a Unix socket and reports each worker's exit back.
 * It loads no application code; workers boot the app themselves and share its compiled code through
 * this process's opcache.
 *
 * Normal mode receives each stub's stdio by SCM_RIGHTS and verifies the peer with the kernel (FFI);
 * shared-log mode (Linux without FFI) proves the peer through /proc and lets workers keep this
 * template's stdio, which is the shared log.
 *
 * main() returns only in forked workers, with the job to run at the launcher's top level.
 */
final class WorkServer
{
    private const READ_DEADLINE = 5.0;

    private const ADMIT_DEADLINE = 5.0;

    private const IDLE_EXIT = 60.0;

    private const BREAKER_EXITS = 5;

    private const BREAKER_WINDOW = 300.0;

    private const MARKER_TTL = 900;

    private const WASTE_RETIRE_PERCENT = 20.0;

    /** A fresh template holds other spawns at most this long while its first worker warms the opcache. */
    private const WARM_WAIT = 8.0;

    /** @var resource|null */
    private $listener = null;

    private ?int $socketIno = null;

    private string $runtime;

    private string $key16;

    private string $release;

    private string $output;

    private string $binary;

    /** @var array<string, string> */
    private array $settings;

    private bool $native;

    /**
     * One entry per connection. `state`: new → (proving) → (waiting) → parked → running → done.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $clients = [];

    /** @var array<int, string> worker pid => client id */
    private array $workers = [];

    /** @var array<string, array{pid: int, start: ?string}> request id => the stub that owns its files */
    private array $requestFiles = [];

    /** @var list<float> */
    private array $crashes = [];

    private bool $retiring = false;

    private float $lastActivity;

    private float $lastHousekeeping = 0.0;

    private float $lastRetireCheck = 0.0;

    private float $lastSelectFailure = 0.0;

    /** The ini's max_execution_time, which this template disarmed for itself and re-arms in each worker. */
    private int $timeLimit = 0;

    /** The socket and pid file are no longer ours to remove (removed, or replaced by a successor). */
    private bool $unpublished = false;

    /**
     * Warm-up: a fresh template's opcache is empty. A worker that compiles the app keeps the compiler's
     * peak heap for its whole life (about 18 MB on the workbench), and workers that boot together each
     * compile privately. So a fresh template first forks a throwaway child that boots the app and exits
     * at its first loop, before reserving any job; the real workers are forked once it is ready (or
     * after WARM_WAIT) and share what it compiled.
     */
    private bool $warm = false;

    private ?float $warmingUntil = null;

    private ?int $warmupPid = null;

    private ?Channel $warmup = null;

    /** Shared-log mode: the output log of this template and its workers. */
    private ?SharedLog $sharedLog = null;

    public static function main(): ChildJob
    {
        $job = null;

        try {
            Runtime::$role = 'template';
            RoleGuard::install();
            $job = (new self)->run();

            return $job;
        } catch (Throwable $e) {
            if (Runtime::isChild()) {
                @fwrite(STDERR, 'queue-prefork child setup failed: '.$e->getMessage()."\n");
                RoleGuard::shim(1);
            }

            $runtime = (string) getenv('QP_RUNTIME');
            $key16 = (string) getenv('QP_KEY16');
            self::logTo($runtime, $key16, 'error: '.get_class($e).': '.$e->getMessage());

            // Without this, every worker start would launch another template that fails the same way.
            self::markStock($runtime, $key16, 'template error: '.get_class($e), (string) getenv('QP_RELEASE'));

            RoleGuard::exitServer(70);
        } finally {
            if ($job === null && Runtime::isServer()) {
                RoleGuard::exitServer(70);
            }
        }
    }

    private function __construct()
    {
        $this->runtime = (string) getenv('QP_RUNTIME');
        $this->key16 = (string) getenv('QP_KEY16');
        $this->release = (string) getenv('QP_RELEASE');
        $this->output = getenv('QP_OUTPUT') === 'shared-log' ? 'shared-log' : 'normal';
        $this->settings = (array) json_decode((string) getenv('QP_SETTINGS'), true) + TemplateKey::SETTINGS;
        $this->binary = realpath(PHP_BINARY) ?: PHP_BINARY;
        $this->native = $this->output === 'normal' && Native::available();
        $this->sharedLog = $this->output === 'shared-log' ? new SharedLog(
            (string) getenv('QP_LOG'),
            (float) $this->settings['QUEUE_PREFORK_LOG_MAX_MB'] * 1048576,
            (int) $this->settings['QUEUE_PREFORK_LOG_FILES'],
        ) : null;
        $this->lastActivity = microtime(true);
        Runtime::$runtimeDir = $this->runtime;
        Runtime::$config = ['exit_grace' => 15];

        // Load every class now: the release this template was started from may be pruned while it drains.
        $classes = [
            Audit::class, Channel::class, CommandLine::class, Fd::class, Identity::class, Native::class, RuntimeDir::class,
            TemplateKey::class, ChildRoutine::class, ChildJob::class, RoleGuard::class, Signals::class, Opcache::class,
            Preflight::class, WorkerContext::class, SharedLog::class, Process::class,
        ];

        foreach ($classes as $class) {
            class_exists($class);
        }
        @cli_set_process_title("php [qp-template:{$this->key16}]");

        if ($this->runtime === '' || $this->key16 === '' || $this->release === '') {
            throw new \RuntimeException('started without QP_RUNTIME, QP_KEY16 and QP_RELEASE');
        }

        RuntimeDir::check($this->runtime);
    }

    private function run(): ChildJob
    {
        $reasons = $this->startupReasons();

        if ($reasons !== []) {
            $this->writeMarker('template refused: '.implode('; ', $reasons));
            $this->log('refused: '.implode('; ', $reasons));
            RoleGuard::exitServer(78);
        }

        if (! $this->listen()) {
            RoleGuard::exitServer(0); // another template of this key is serving
        }

        $this->sharedLog?->takeLease();
        $this->sharedLog?->pruneSets();

        // A max_execution_time from the ini would end this long-lived process. Keep the value for the
        // workers (a fork doesn't inherit the timer, so each one re-arms it) and disarm it here.
        $this->timeLimit = function_exists('set_time_limit') ? (int) ini_get('max_execution_time') : 0;

        if ($this->timeLimit > 0) {
            set_time_limit(0);
        }

        $this->log('serving '.$this->release.' ('.$this->output.' mode)');
        Signals::install([SIGCHLD, SIGTERM, SIGINT, SIGQUIT, SIGHUP], [SIGUSR1, SIGUSR2, SIGPIPE]);

        while (true) {
            foreach (array_keys(Signals::take()) as $signal) {
                if ($signal !== SIGCHLD) {
                    $this->retire('signal '.$signal);
                }
            }

            $read = $this->listener !== null ? [$this->listener] : [];

            if ($this->warmup !== null && $this->warmup->isOpen()) {
                $read[] = $this->warmup->stream();
            }

            foreach ($this->clients as $client) {
                if (is_resource($client['stream'])) {
                    $read[] = $client['stream'];
                }

                if (isset($client['worker']) && $client['worker']['channel']->isOpen()) {
                    $read[] = $client['worker']['channel']->stream();
                }
            }

            $write = $except = null;

            if ($read !== [] && @stream_select($read, $write, $except, 0, 200000) === false) {
                // Out of select()'s reach (a descriptor past 1023) or interrupted: don't spin.
                if (microtime(true) - $this->lastSelectFailure > 60) {
                    $this->log('stream_select failed: '.(error_get_last()['message'] ?? 'unknown error'));
                }

                $this->lastSelectFailure = microtime(true);
                $read = [];
                usleep(100000);
            } elseif ($read === []) {
                usleep(200000);
            }

            $this->accept($this->listener !== null && in_array($this->listener, $read, true));

            foreach (array_keys($this->clients) as $id) {
                $job = $this->readClient($id) ?? $this->readWorker($id);

                if ($job !== null) {
                    return $job;
                }
            }

            $this->readWarmup();
            $job = $this->releaseWarmed();

            if ($job !== null) {
                return $job;
            }

            $this->reap();

            if (microtime(true) - $this->lastHousekeeping >= 1.0) {
                $job = $this->housekeeping();

                if ($job !== null) {
                    return $job;
                }
            }
        }
    }

    /** @return list<string> */
    private function startupReasons(): array
    {
        $reasons = [];

        if (TemplateKey::iniHash() !== (string) getenv('QP_INI_HASH')) {
            $reasons[] = 'its ini differs from the stub\'s: '.implode(', ', $this->iniDifferences());
        }

        if (! Opcache::enabled()) {
            $reasons[] = 'opcache is not active in the template';
        }

        if ($this->settings['QUEUE_PREFORK_THREAD_AUDIT'] !== '0') {
            $reasons = array_merge($reasons, Audit::threads());
        }

        $allowed = array_values(array_filter(explode(',', $this->settings['QUEUE_PREFORK_ALLOW_DESCRIPTORS'])));
        $reasons = array_merge($reasons, Audit::descriptors([], $allowed), Preflight::extensions([]));

        return $reasons;
    }

    /** @return list<string> ini names whose startup values differ from the stub's snapshot */
    private function iniDifferences(): array
    {
        $theirs = json_decode((string) @file_get_contents("{$this->runtime}/{$this->key16}.ini.json"), true);

        if (! is_array($theirs)) {
            return ['(no snapshot)'];
        }

        return TemplateKey::iniDifferences($theirs);
    }

    private function listen(): bool
    {
        $path = "{$this->runtime}/{$this->key16}.sock";
        // A deploy restarts every worker at once: PHP's default backlog of 32 would refuse the rest.
        $context = stream_context_create(['socket' => ['backlog' => 4096]]);
        $listener = @stream_socket_server('unix://'.$path, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($listener === false) {
            $this->log("not serving: {$path}: {$error}");

            return false;
        }

        stream_set_blocking($listener, false);
        $this->listener = $listener;
        clearstatcache(true, $path);
        $this->socketIno = @fileinode($path) ?: null;
        RuntimeDir::writeAtomic($this->runtime, "{$this->key16}.pid", getmypid().' '.Identity::startTime(getmypid())."\n");

        return true;
    }

    private function accept(bool $readable): void
    {
        if ($this->listener === null) {
            return;
        }

        $accepted = 0;

        while (($stream = @stream_socket_accept($this->listener, 0)) !== false) {
            $accepted++;

            if ($this->retiring || ! $this->descriptorRoom()) {
                @fwrite($stream, json_encode(['t' => 'refused', 'reason' => $this->retiring ? 'retiring' : 'out of descriptors'])."\n");
                fclose($stream); // the stub boots stock

                continue;
            }

            stream_set_blocking($stream, false);
            $id = 'c'.(int) $stream;
            $this->clients[$id] = [
                'stream' => $stream,
                'fd' => $this->native ? Fd::number($stream) : null,
                'in' => '',
                'fds' => [],
                'state' => 'new',
                'deadline' => microtime(true) + self::READ_DEADLINE,
            ];
            $this->lastActivity = microtime(true);
        }

        if ($readable && $accepted === 0) {
            usleep(100000); // a pending connection we couldn't accept (EMFILE): don't spin on it
        }
    }

    /**
     * Room for one more handshake (its stream, three stdio descriptors, then a channel) on top of those
     * still pending: below both select()'s limit of descriptor 1024 and the template's own open-files
     * limit, with a margin.
     */
    private function descriptorRoom(): bool
    {
        $soft = (posix_getrlimit() ?: [])['soft openfiles'] ?? 1024;
        $limit = min(1024, is_numeric($soft) ? (int) $soft : PHP_INT_MAX);
        $reserved = 0;

        foreach ($this->clients as $client) {
            // What a pending handshake will still open: its three stdio descriptors, then a channel.
            if (! isset($client['worker'])) { // a running worker's descriptors are counted already
                $reserved += ($this->native && $client['fds'] === [] ? 3 : 0) + 1;
            }
        }

        // New descriptors take the lowest free numbers, so the count is what nears the limit.
        return count(Audit::openFds()) + $reserved + 5 < $limit - max(8, intdiv($limit, 16));
    }

    private function readClient(string $id): ?ChildJob
    {
        $client = &$this->clients[$id];

        if (! is_resource($client['stream'] ?? null)) {
            return null;
        }

        if ($this->native && $client['fd'] !== null) {
            try {
                $got = Native::get()->receive($client['fd'], 65536, 4);
            } catch (Throwable) {
                $got = ['data' => '', 'fds' => [], 'eof' => true];
            }

            if ($got === null) {
                return null;
            }

            $client['fds'] = [...$client['fds'], ...$got['fds']];
            $data = $got['data'];
            $eof = $got['eof'];
        } else {
            $data = (string) @fread($client['stream'], 65536);
            $eof = $data === '' && feof($client['stream']);
        }

        if ($eof) {
            $this->stubGone($id, 'connection closed');

            return null;
        }

        $client['in'] .= $data;

        if (strlen($client['in']) > 1048576 + 4096) {
            $this->drop($id, 'request too large');

            return null;
        }

        while (isset($this->clients[$id]) && ($nl = strpos($this->clients[$id]['in'], "\n")) !== false) {
            $line = substr($this->clients[$id]['in'], 0, $nl);
            $this->clients[$id]['in'] = substr($this->clients[$id]['in'], $nl + 1);
            $message = json_decode($line, true);
            $job = $this->onClientMessage($id, is_array($message) ? $message : []);

            if ($job !== null) {
                return $job;
            }
        }

        return null;
    }

    private function onClientMessage(string $id, array $message): ?ChildJob
    {
        $client = $this->clients[$id];

        return match ([$client['state'], $message['t'] ?? null]) {
            ['new', 'spawn'] => $this->onSpawn($id, $message),
            ['proving', 'proved'] => $this->onProved($id),
            ['parked', 'go'] => $this->onGo($id),
            ['new', 'status'] => $this->onStatus($id),
            ['new', 'retire'] => $this->onRetireRequest($id),
            default => $this->drop($id, 'unexpected '.json_encode($message['t'] ?? null).' in state '.$client['state']),
        };
    }

    private function onSpawn(string $id, array $message): ?ChildJob
    {
        if ($this->retiring) {
            return $this->refuse($id, 'retiring');
        }

        if (($marker = $this->activeMarker()) !== null) {
            return $this->refuse($id, 'stock marker: '.$marker);
        }

        if ((int) ($message['protocol'] ?? 0) !== Runtime::PROTOCOL) {
            return $this->refuse($id, 'protocol');
        }

        $claimed = (int) ($message['pid'] ?? 0);
        $this->clients[$id]['request'] = $message;

        if ($this->native) {
            if (Native::get()->peerPid((int) $this->clients[$id]['fd']) !== $claimed) {
                return $this->refuse($id, 'peer pid mismatch');
            }

            if (count($this->clients[$id]['fds']) !== 3) {
                return $this->refuse($id, 'expected stdin, stdout and stderr');
            }

            return $this->verifyAndFork($id);
        }

        // Shared-log mode: prove the claimed pid controls this connection.
        if (PHP_OS_FAMILY !== 'Linux') {
            return $this->refuse($id, 'no peer check on this platform');
        }

        $token = "{$this->runtime}/tok-".bin2hex(random_bytes(12));

        if (@file_put_contents($token, '') === false) {
            return $this->refuse($id, 'cannot create a token');
        }

        @chmod($token, 0600);
        $this->clients[$id]['token'] = $token;
        $this->clients[$id]['state'] = 'proving';
        $this->send($id, json_encode(['t' => 'prove', 'token' => $token])."\n");

        return null;
    }

    private function onProved(string $id): ?ChildJob
    {
        $client = $this->clients[$id];
        $pid = (int) ($client['request']['pid'] ?? 0);
        $token = @stat($client['token']);
        $proved = false;

        // By file identity, not by path: the runtime dir's path may go through a symlink.
        foreach ($token === false ? [] : (@scandir("/proc/{$pid}/fd") ?: []) as $fd) {
            $open = ctype_digit($fd) ? @stat("/proc/{$pid}/fd/{$fd}") : false;

            if ($open !== false && $open['dev'] === $token['dev'] && $open['ino'] === $token['ino']) {
                $proved = true;

                break;
            }
        }

        @unlink($client['token']);
        $this->clients[$id]['token'] = null;

        return $proved ? $this->verifyAndFork($id) : $this->refuse($id, 'proof of control failed');
    }

    private function verifyAndFork(string $id): ?ChildJob
    {
        $request = $this->clients[$id]['request'];

        try {
            $context = PHP_OS_FAMILY === 'Linux'
                ? WorkerContext::fromProc((int) $request['pid'], $request, $this->binary, $this->release)
                : WorkerContext::fromMessage($request, $this->release);
        } catch (Throwable $e) {
            return $this->refuse($id, $e->getMessage());
        }

        $this->clients[$id]['context'] = $context;
        $wait = Opcache::freshCacheWait($this->release);

        if ($wait > 0) {
            $this->clients[$id]['state'] = 'waiting';
            $this->clients[$id]['deadline'] = microtime(true) + $wait;

            return null;
        }

        return $this->forkOrWarm($id);
    }

    private function forkOrWarm(string $id): ?ChildJob
    {
        if ($this->settings['QUEUE_PREFORK_WARMUP'] === '0') {
            $this->warm = true; // no throwaway boot: the first worker compiles the app
        }

        if (! $this->warm && $this->warmingUntil === null) {
            $this->warmingUntil = microtime(true) + self::WARM_WAIT;
            $job = $this->forkWarmup($this->clients[$id]['context']);

            if ($job !== null) {
                return $job;
            }
        }

        if (! $this->warm && microtime(true) < (float) $this->warmingUntil) {
            $this->clients[$id]['state'] = 'waiting';
            $this->clients[$id]['deadline'] = $this->warmingUntil;
            $this->clients[$id]['warming'] = true;
            $this->send($id, "{\"t\":\"waiting\"}\n"); // the stub extends its deadline

            return null;
        }

        return $this->fork($id);
    }

    /** The throwaway warm-up child: the first request's context, the template's own stdio, no stub. */
    private function forkWarmup(array $context): ?ChildJob
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            $this->warm = true; // no warm-up then; fork the workers as they come

            return null;
        }

        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous);
        $pid = Process::fork();

        if ($pid === 0) {
            Runtime::$warmup = true;
            Runtime::$output = $this->output;
            fclose($pair[0]);
            $close = [$this->listener];

            foreach ($this->clients as $client) {
                $close[] = $client['stream'];

                if (isset($client['worker'])) {
                    $close[] = $client['worker']['channel'];
                }

                if ($this->native) {
                    foreach ($client['fds'] as $fd) {
                        Native::get()->close($fd);
                    }
                }
            }

            pcntl_sigprocmask(SIG_SETMASK, $previous);

            // The template's own stdio: /dev/null and its log, so whatever the boot prints lands there.
            return ChildRoutine::become(
                'worker', $pair[1], $close, $context['command'], $context['cwd'], $context['env'], 'warmup',
                prepend: $context['prepend'], entry: $context['entry'],
            );
        }

        fclose($pair[1]);
        pcntl_sigprocmask(SIG_SETMASK, $previous);

        if ($pid < 0) {
            fclose($pair[0]);
            $this->warm = true;

            return null;
        }

        $this->warmupPid = $pid;
        $this->warmup = new Channel($pair[0]);
        $this->warmup->send(['t' => 'admit', 'paused' => false]);
        $this->warmup->flushBlocking(1.0);
        $this->log('warming up with child '.$pid);

        return null;
    }

    /** Forks the spawns held for the warm-up as soon as the first worker is ready. */
    private function releaseWarmed(): ?ChildJob
    {
        if (! $this->warm) {
            return null;
        }

        foreach (array_keys($this->clients) as $id) {
            if (($this->clients[$id]['state'] ?? null) === 'waiting' && ! empty($this->clients[$id]['warming'])) {
                $this->clients[$id]['warming'] = false;
                $job = $this->fork($id);

                if ($job !== null) {
                    return $job;
                }
            }
        }

        return null;
    }

    private function fork(string $id): ?ChildJob
    {
        $context = $this->clients[$id]['context'];
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        if ($pair === false) {
            return $this->refuse($id, 'socket pair');
        }

        pcntl_sigprocmask(SIG_BLOCK, [SIGCHLD], $previous);

        try {
            $pid = Process::fork();
        } catch (Throwable) {
            $pid = -1;
        }

        if ($pid === 0) {
            return $this->becomeWorker($id, $context, $pair, $previous);
        }

        fclose($pair[1]);

        foreach ($this->clients[$id]['fds'] as $fd) {
            Native::get()->close($fd); // the child has its own copies
        }

        $this->clients[$id]['fds'] = [];

        if ($pid < 0) {
            fclose($pair[0]);
            pcntl_sigprocmask(SIG_SETMASK, $previous);

            return $this->refuse($id, 'fork failed');
        }

        $start = Identity::startTime($pid);

        if ($start === null) {
            // Without its start time the stub could never check the worker's identity, so it would
            // forward no signal: never hand such a worker out (it is still parked, so nothing ran).
            posix_kill($pid, SIGKILL);
            fclose($pair[0]);
            pcntl_sigprocmask(SIG_SETMASK, $previous);

            return $this->refuse($id, 'cannot read the new worker\'s start time');
        }

        $this->workers[$pid] = $id;
        $this->clients[$id]['worker'] = [
            'pid' => $pid,
            'start' => $start,
            'channel' => new Channel($pair[0]),
            'armed' => true,
            'admitted' => false,
            'ready' => false,
            'declined' => null,
            'forkedAt' => microtime(true),
        ];
        $this->clients[$id]['state'] = 'parked';
        $this->clients[$id]['deadline'] = microtime(true) + self::ADMIT_DEADLINE;
        $this->requestFiles[$context['id']] = ['pid' => $context['pid'], 'start' => $context['start']];
        pcntl_sigprocmask(SIG_SETMASK, $previous);
        $this->send($id, json_encode(['t' => 'spawned', 'id' => $context['id'], 'pid' => $pid, 'start' => $start])."\n");

        return null;
    }

    /** In the forked child: drop everything of the template's, then park for admission. */
    private function becomeWorker(string $id, array $context, array $pair, array $previous): ChildJob
    {
        Runtime::$stubPid = $context['pid'];
        Runtime::$stubStart = $context['start'];
        Runtime::$output = $this->output;
        Runtime::$stubStdio = $context['stdio'];
        fclose($pair[0]);
        $own = $this->clients[$id]['fds'];
        $close = [$this->listener, $this->warmup];

        foreach ($this->clients as $other => $client) {
            $close[] = $client['stream']; // the stub's connection too: only the template may hold it

            if (isset($client['worker'])) {
                $close[] = $client['worker']['channel'];
            }

            if ($other !== $id && $this->native) {
                foreach ($client['fds'] as $fd) {
                    Native::get()->close($fd);
                }
            }
        }

        pcntl_sigprocmask(SIG_SETMASK, $previous);
        $native = $this->native;
        $timeLimit = $this->timeLimit;

        $setup = static function () use ($own, $context, $native, $timeLimit): void {
            if ($native && count($own) === 3) {
                foreach ($own as $target => $fd) {
                    Native::get()->dup2($fd, $target);
                    Native::get()->close($fd);
                }
            }

            WorkerContext::apply($context);

            if ($timeLimit > 0) {
                set_time_limit($timeLimit); // as a stock process's timer, which runs from its start
            }
        };

        return ChildRoutine::become(
            'worker', $pair[1], $close, $context['command'], $context['cwd'], $context['env'], $context['id'], $setup,
            prepend: $context['prepend'], entry: $context['entry'],
        );
    }

    private function onGo(string $id): ?ChildJob
    {
        $worker = &$this->clients[$id]['worker'];
        $worker['channel']->send(['t' => 'admit', 'paused' => false]);
        $worker['channel']->flushBlocking(1.0);
        $worker['admitted'] = true;
        $this->clients[$id]['state'] = 'running';

        return null;
    }

    private function readWorker(string $id): ?ChildJob
    {
        if (! isset($this->clients[$id]['worker'])) {
            return null;
        }

        $worker = &$this->clients[$id]['worker'];

        foreach ($worker['channel']->receive() as $message) {
            if (($message['t'] ?? null) === 'ready') {
                $worker['ready'] = true;

                if (! $this->warm) {
                    $this->warm = true; // no warm-up child (e.g. it failed): the first worker warmed it
                }
            } elseif (($message['t'] ?? null) === 'stock') {
                $worker['declined'] = (string) ($message['reason'] ?? 'declined');

                if (($message['reason'] ?? null) === 'logs-to-stdio') {
                    $this->writeMarker('logs-to-stdio');
                }
            }
        }

        return null;
    }

    private function readWarmup(): void
    {
        if ($this->warmup === null) {
            return;
        }

        foreach ($this->warmup->receive() as $message) {
            if (($message['t'] ?? null) === 'ready' && ! $this->warm) {
                $this->warm = true;
                $this->log('warm: the warm-up child booted the app');
            }
        }

        if ($this->warmup->eof) {
            $this->warmup->close();
            $this->warmup = null;
        }
    }

    private function reap(): void
    {
        while (($pid = pcntl_waitpid(-1, $raw, WNOHANG)) > 0) {
            if ($pid === $this->warmupPid) {
                $this->warmupPid = null;
                $this->warm = true; // ready or not, it's done: fork the workers
            }

            $id = $this->workers[$pid] ?? null;
            unset($this->workers[$pid]);

            if ($id === null || ! isset($this->clients[$id]['worker'])) {
                continue;
            }

            $this->readWorker($id); // its last messages: a `stock` or `ready` sent just before it exited

            $worker = &$this->clients[$id]['worker'];
            $worker['armed'] = false; // the stub-death kill is armed only between fork and reap
            $signal = pcntl_wifsignaled($raw) ? pcntl_wtermsig($raw) : null;
            $code = $signal === null ? pcntl_wexitstatus($raw) : 128 + $signal;
            $status = $worker['declined'] !== null ? 'stock' : ($signal !== null ? "s{$signal}" : (string) $code);

            $bootCrash = ! $worker['ready'] && $worker['declined'] === null && $this->crashLike($code, $signal);

            if ($bootCrash && microtime(true) - $worker['forkedAt'] < 120) {
                // It died while booting, before any job: the stub runs stock in place. A process
                // manager that gives up after a few failed starts (supervisord's startretries, systemd's
                // start limit) never sees this one fail, and if stock fails too, it sees that.
                $status = 'stock';
                $this->crashed();
            }

            $this->send($id, $status."\n");
            $this->finish($id);
        }
    }

    /** Exits that look like a crash at boot; stops, timeouts and deliberate exits don't count. */
    private function crashLike(int $code, ?int $signal): bool
    {
        if ($signal !== null) {
            return ! in_array($signal, [SIGHUP, SIGINT, SIGQUIT, SIGTERM, SIGKILL], true);
        }

        return ! in_array($code, [0, 72, 75, 137], true);
    }

    private function crashed(): void
    {
        $now = microtime(true);
        $this->crashes = array_values(array_filter($this->crashes, fn (float $at) => $now - $at < self::BREAKER_WINDOW));
        $this->crashes[] = $now;

        if (count($this->crashes) >= self::BREAKER_EXITS) {
            $this->writeMarker('crash breaker: '.count($this->crashes).' workers exited before their first loop');
            $this->crashes = [];
        }
    }

    private function housekeeping(): ?ChildJob
    {
        $now = microtime(true);
        $this->lastHousekeeping = $now;
        Identity::forgetCache();

        if ($this->warmupPid !== null && $this->warmingUntil !== null && $now >= $this->warmingUntil) {
            // A warm-up boot still here never got to leave (a listener stopping the worker events, a
            // Laravel without WorkerStarting in maintenance mode): it must not become a worker nobody
            // supervises. Still our unreaped child, so the pid is its own.
            posix_kill($this->warmupPid, SIGKILL);
            $this->log('killed the warm-up child '.$this->warmupPid.' at the warm-up deadline');
            $this->warmingUntil = null;
            $this->warm = true;
        }

        foreach (array_keys($this->clients) as $id) {
            $client = $this->clients[$id] ?? null;

            if ($client === null) {
                continue;
            }

            if (in_array($client['state'], ['new', 'proving'], true) && $now > $client['deadline']) {
                $this->drop($id, 'request deadline');
            } elseif ($client['state'] === 'waiting' && $now >= $client['deadline']) {
                $job = empty($client['warming']) ? $this->forkOrWarm($id) : $this->fork($id);

                if ($job !== null) {
                    return $job;
                }
            } elseif ($client['state'] === 'parked' && $now > $client['deadline']) {
                $this->stubGone($id, 'no go within '.self::ADMIT_DEADLINE.' s');
            } elseif (in_array($client['state'], ['parked', 'running'], true)) {
                $request = $client['context'];

                if (! Identity::alive($request['pid'], $request['start'])) {
                    $this->stubGone($id, 'stub gone');
                }
            }
        }

        foreach ($this->requestFiles as $request => $owner) {
            if (! Identity::alive($owner['pid'], $owner['start'])) {
                @unlink("{$this->runtime}/req-{$request}.argv");
                @unlink("{$this->runtime}/req-{$request}.st");
                unset($this->requestFiles[$request]);
            }
        }

        if ($now - $this->lastRetireCheck >= 10.0) {
            $this->lastRetireCheck = $now;
            $this->retireChecks();
        }

        if ($this->retiring && ! $this->unpublished) {
            $this->unpublish(0.0);
        }

        if ($this->clients !== [] || $this->workers !== [] || $this->warmupPid !== null) {
            $this->lastActivity = $now;
        } elseif ($this->retiring || $now - $this->lastActivity > self::IDLE_EXIT) {
            $this->retire($this->retiring ? 'drained' : 'idle');

            if (! $this->unpublish(2.0)) {
                // Leaving the files is safe: the pid file names a dead template, so a launcher replaces them.
                $this->log('exiting without the launch lock; the next launcher replaces the files');
            }

            $this->log('exiting');

            $this->sharedLog?->releaseLease(); // the drain is over: this template no longer owns the log

            RoleGuard::exitServer(0);
        }

        return null;
    }

    private function retireChecks(): void
    {
        $report = Opcache::report();

        if (($report['wasted_percent'] ?? 0) > self::WASTE_RETIRE_PERCENT) {
            $this->retire('opcache waste '.$report['wasted_percent'].' %');
        }

        if (PHP_OS_FAMILY === 'Linux' && str_ends_with((string) @readlink('/proc/self/exe'), ' (deleted)')) {
            $this->retire('PHP binary replaced');
        }

        $this->sharedLog?->rotateIfNeeded(fn () => $this->dropConnections());
    }

    /**
     * In a forked helper: let go of the listener, every stub connection, channel and received
     * descriptor, so that a slow helper can't keep a stub from seeing the template's death.
     */
    private function dropConnections(): void
    {
        if (is_resource($this->listener)) {
            @fclose($this->listener);
        }

        $this->warmup?->close();

        foreach ($this->clients as $client) {
            if (is_resource($client['stream'] ?? null)) {
                @fclose($client['stream']);
            }

            if (isset($client['worker'])) {
                $client['worker']['channel']->close();
            }

            foreach ($this->native ? ($client['fds'] ?? []) : [] as $fd) {
                Native::get()->close($fd);
            }
        }
    }

    /** The stub is gone (or never admitted its child): kill a worker that hasn't been reaped yet. */
    private function stubGone(string $id, string $why): void
    {
        $worker = $this->clients[$id]['worker'] ?? null;

        if ($worker !== null && $worker['armed'] && Identity::alive($worker['pid'], $worker['start'])) {
            $group = $worker['admitted'] && $this->settings['QUEUE_PREFORK_KILL_GROUP'] !== '0';

            if (! $group || ! @posix_kill(-$worker['pid'], SIGKILL)) {
                @posix_kill($worker['pid'], SIGKILL);
            }

            $this->log("killed worker {$worker['pid']}: {$why}");
        }

        if ($worker !== null && $worker['armed']) {
            // keep the record until the worker is reaped; just stop talking to the stub
            $this->closeStream($id);
            $this->clients[$id]['state'] = 'orphaned';

            return;
        }

        $this->finish($id);
    }

    private function onStatus(string $id): ?ChildJob
    {
        $workers = 0;

        foreach ($this->clients as $client) {
            $workers += isset($client['worker']) && $client['worker']['armed'] ? 1 : 0;
        }

        $this->send($id, json_encode([
            'pid' => getmypid(),
            'key' => $this->key16,
            'release' => $this->release,
            'output' => $this->output,
            'workers' => $workers,
            'retiring' => $this->retiring,
            'marker' => $this->activeMarker(),
            'opcache' => Opcache::report(),
        ], JSON_UNESCAPED_SLASHES)."\n");
        $this->finish($id);

        return null;
    }

    private function onRetireRequest(string $id): ?ChildJob
    {
        $this->retire('requested');
        $this->send($id, "{\"t\":\"retiring\"}\n");
        $this->finish($id);

        return null;
    }

    /** Stop accepting, then unpublish the socket and the pid file. */
    private function retire(string $why): void
    {
        if ($this->retiring) {
            return;
        }

        $this->retiring = true;

        if ($this->listener !== null) {
            while (($stream = @stream_socket_accept($this->listener, 0)) !== false) {
                @fwrite($stream, "{\"t\":\"refused\",\"reason\":\"retiring\"}\n");
                fclose($stream);
            }

            fclose($this->listener); // until unpublished, connects are refused: stubs see a live, busy template
            $this->listener = null;
        }

        $this->unpublish(0.5); // housekeeping retries until it gets the lock
        $this->log('retiring: '.$why);
    }

    /**
     * Unlink the socket and the pid file if they are still ours, under the key's launch lock, which a
     * launcher holds while it replaces a dead template's files and starts a successor: what we check
     * is then what we unlink. Without the lock, nothing is touched; false means try again.
     */
    private function unpublish(float $wait): bool
    {
        if ($this->unpublished) {
            return true;
        }

        $lock = @fopen("{$this->runtime}/{$this->key16}.lock", 'c');

        if ($lock === false) {
            return false;
        }

        $locked = false;

        for ($deadline = microtime(true) + $wait; ! ($locked = flock($lock, LOCK_EX | LOCK_NB)) && microtime(true) < $deadline;) {
            usleep(50000);
        }

        if ($locked) {
            $path = "{$this->runtime}/{$this->key16}.sock";
            clearstatcache(true, $path);

            if ($this->socketIno !== null && @fileinode($path) === $this->socketIno) {
                $pidFile = "{$this->runtime}/{$this->key16}.pid";

                if ((int) @file_get_contents($pidFile) === getmypid()) {
                    @unlink($pidFile);
                }

                @unlink($path);
            }

            $this->unpublished = true;
            flock($lock, LOCK_UN);
        }

        fclose($lock);

        return $locked;
    }

    private function refuse(string $id, string $reason): ?ChildJob
    {
        $this->send($id, json_encode(['t' => 'refused', 'reason' => $reason])."\n");
        $this->log('refused a stub: '.$reason);
        $this->finish($id);

        return null;
    }

    private function drop(string $id, string $why): ?ChildJob
    {
        $this->log('dropped a client: '.$why);
        $this->stubGone($id, $why);

        return null;
    }

    private function send(string $id, string $data): void
    {
        $client = $this->clients[$id] ?? null;

        if ($client === null || ! is_resource($client['stream'])) {
            return;
        }

        if ($this->native && $client['fd'] !== null) {
            Native::get()->send($client['fd'], $data);
        } else {
            @fwrite($client['stream'], $data);
        }
    }

    private function closeStream(string $id): void
    {
        if (is_resource($this->clients[$id]['stream'] ?? null)) {
            fclose($this->clients[$id]['stream']);
        }

        foreach ($this->clients[$id]['fds'] ?? [] as $fd) {
            Native::get()->close($fd);
        }

        $this->clients[$id]['fds'] = [];
        $this->clients[$id]['stream'] = null;
    }

    private function finish(string $id): void
    {
        $this->closeStream($id);

        if (! empty($this->clients[$id]['token'])) {
            @unlink($this->clients[$id]['token']);
        }

        if (isset($this->clients[$id]['worker'])) {
            $this->clients[$id]['worker']['channel']->close();
        }

        unset($this->clients[$id]);
    }

    private function writeMarker(string $reason): void
    {
        self::markStock($this->runtime, $this->key16, $reason, $this->release);
    }

    /** Holds this key stock for a while: its stubs boot stock instead of launching another template. */
    private static function markStock(string $runtime, string $key16, string $reason, string $release): void
    {
        $marker = ['reason' => $reason, 'until' => time() + self::MARKER_TTL, 'release' => $release];

        try {
            RuntimeDir::writeAtomic($runtime, "{$key16}.stock", json_encode($marker, JSON_UNESCAPED_SLASHES));
        } catch (Throwable) {
        }
    }

    private function activeMarker(): ?string
    {
        $marker = json_decode((string) @file_get_contents("{$this->runtime}/{$this->key16}.stock"), true);

        return is_array($marker) && ($marker['until'] ?? 0) > time() ? (string) $marker['reason'] : null;
    }

    private function log(string $line): void
    {
        self::logTo($this->runtime, $this->key16, $line);
    }

    private static function logTo(string $runtime, string $key16, string $line): void
    {
        if ($runtime !== '' && $key16 !== '') {
            @file_put_contents("{$runtime}/{$key16}.log", date('c').' ['.getmypid().'] '.$line."\n", FILE_APPEND);
        }
    }
}
