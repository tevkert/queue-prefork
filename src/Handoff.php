<?php

namespace Tevkert\QueuePrefork;

use Tevkert\QueuePrefork\Server\Signals;
use Tevkert\QueuePrefork\Support\Audit;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\EarlyConfig;
use Tevkert\QueuePrefork\Support\Fd;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Native;
use Tevkert\QueuePrefork\Support\Process;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\Switches;
use Tevkert\QueuePrefork\Support\TemplateKey;
use Throwable;

/**
 * The stub side of the per-worker fork, run from src/hook.php inside
 * `php artisan queue:work …` before Laravel boots.
 *
 * It asks this release's shared template for a worker, then turns this process into bin/stub.sh,
 * keeping the pid the process manager watches. Every failure before the template's worker is released
 * returns, and the process boots stock exactly as it would without the package. After `go` it never
 * boots stock itself: a declined worker comes back as the `stock` status, and the stub execs the
 * original command in place.
 */
final class Handoff
{
    /** What the handoff, the stub and the template call. */
    private const FUNCTIONS = [
        'pcntl_fork',
        'pcntl_exec',
        'pcntl_waitpid',
        'pcntl_signal',
        'pcntl_signal_dispatch',
        'pcntl_sigprocmask',
        'pcntl_alarm',
        'pcntl_signal_get_handler',
        'pcntl_wifsignaled',
        'pcntl_wtermsig',
        'pcntl_wexitstatus',
        'posix_kill',
        'posix_setsid',
        'posix_setpgid',
        'posix_getppid',
        'posix_getrlimit',
        'posix_setrlimit',
        'proc_open',
        'stream_socket_client',
        'stream_socket_server',
        'stream_socket_accept',
        'stream_socket_pair',
    ];

    private const CONNECT_TIMEOUT = 5.0;

    private const LAUNCH_WAIT = 3.0;

    /** @var resource|null shared-log mode: the PHP stream to the template */
    private $stream = null;

    private ?int $fd = null;

    private string $runtime;

    private string $key16;

    private string $mode;

    private string $release;

    private string $entry;

    /** @var array<string, string> */
    private array $settings;

    private string $iniHash;

    /** @var list<string> the original command line, PHP_BINARY first, for the stock exec */
    private array $command = [];

    /** Received bytes after the last complete reply line. */
    private string $inbox = '';

    /** Called by the hook for `queue:work`: returns when the process should boot stock. */
    public static function attempt(): void
    {
        try {
            $handoff = self::eligible();

            if ($handoff !== null) {
                $handoff->run();
            }
        } catch (Throwable $e) {
            self::note('stock: '.$e->getMessage());
        }
    }

    /** The checks that need nothing but this process; null means boot stock. */
    private static function eligible(): ?self
    {
        if (getmypid() === 1) {
            return null;
        }

        $script = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));

        if ($script === false) {
            return null;
        }

        foreach (array_slice((array) ($_SERVER['argv'] ?? []), 2) as $word) {
            if (in_array(explode('=', (string) $word, 2)[0], ['--once', '--stop-when-empty'], true)) {
                return null; // short-lived (queue:listen's children, cron runs): stock, and no template
            }
        }

        $mode = self::readiness(dirname($script))['mode'];

        return $mode === null ? null : new self($script, dirname($script), $mode);
    }

    /**
     * How a `queue:work` of this release, started like this process, would run: `normal`,
     * `shared-log` (Linux without FFI), or null with the reasons it boots stock. `ffi` says why FFI
     * isn't usable, if it isn't. Shared by the handoff and `queue-prefork:doctor`.
     *
     * @return array{mode: ?string, reasons: list<string>, ffi: ?string}
     */
    public static function readiness(string $release): array
    {
        $reasons = self::blockers($release, 'queue-work', self::FUNCTIONS);

        if ($reasons !== []) {
            return ['mode' => null, 'reasons' => $reasons, 'ffi' => null];
        }

        if (Native::available()) {
            return ['mode' => 'normal', 'reasons' => [], 'ffi' => null];
        }

        if (PHP_OS_FAMILY !== 'Linux') {
            return ['mode' => null, 'reasons' => ['FFI is unusable, and only Linux has a fallback'], 'ffi' => Native::reason()];
        }

        if (strtolower((string) EarlyConfig::get($release, 'output', 'QUEUE_PREFORK_OUTPUT')) === 'stock') {
            return ['mode' => null, 'reasons' => ['FFI is unusable, and QUEUE_PREFORK_OUTPUT=stock opts out of shared-log mode'], 'ffi' => Native::reason()];
        }

        return ['mode' => 'shared-log', 'reasons' => [], 'ffi' => Native::reason()];
    }

    /**
     * Why a process of this release can't be handed to a template at all, in either mode: the platform,
     * the off switches, a profiler, disabled functions, no /bin/sh, or a time limit that couldn't be re-armed.
     *
     * @param  string  $mode  queue-work or horizon
     * @param  list<string>  $functions  what the mode calls (a disabled function is undefined in PHP 8)
     * @return list<string>
     */
    public static function blockers(string $release, string $mode, array $functions): array
    {
        $reasons = [];

        if (PHP_SAPI !== 'cli' || ! in_array(PHP_OS_FAMILY, ['Linux', 'Darwin'], true)) {
            $reasons[] = 'unsupported platform '.PHP_OS_FAMILY.' ('.PHP_SAPI.')';
        }

        // Internal, environment only: the stub's and the host's stock exec set it, so they don't hand off again.
        if (in_array(strtolower((string) getenv('QUEUE_PREFORK_HANDOFF')), ['0', 'false', 'off', 'no'], true)) {
            $reasons[] = 'QUEUE_PREFORK_HANDOFF is off';
        }

        $reasons = [...$reasons, ...Switches::off($release, $mode)];

        if (getenv('BLACKFIRE_QUERY')) {
            $reasons[] = 'a Blackfire profile is being taken';
        }

        foreach ($functions as $function) {
            if (! function_exists($function)) {
                $reasons[] = "{$function}() is unavailable";
            }
        }

        if (! is_executable('/bin/sh')) {
            $reasons[] = '/bin/sh is missing';
        }

        if ((int) ini_get('max_execution_time') > 0 && ! function_exists('set_time_limit')) {
            $reasons[] = 'max_execution_time is set and set_time_limit() is disabled: a forked process couldn\'t re-arm it';
        }

        return $reasons;
    }

    private function __construct(string $entry, string $release, string $mode)
    {
        $this->entry = $entry;
        $this->release = $release;
        $this->mode = $mode;
        $key = TemplateKey::compute($release, $mode);
        $this->key16 = $key['key16'];
        $this->settings = $key['settings'];
        $this->runtime = RuntimeDir::shared();
        RuntimeDir::ensure($this->runtime);
        $this->iniHash = $key['ini'];
    }

    private function run(): void
    {
        if ($this->heldStock()) {
            return;
        }

        $ignored = Signals::ignored(); // before connecting: the probes' forks must not hold the connection

        if (! $this->connectOrLaunch()) {
            return;
        }

        $id = bin2hex(random_bytes(8));
        $argv = self::commandLine();
        $json = json_encode($this->spawnMessage($id, $argv, $ignored), JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            self::note('stock: the environment or command line is not valid UTF-8');
            $this->close();

            return;
        }

        $reply = $this->requestWorker($json);

        if (($reply['t'] ?? null) !== 'spawned') {
            self::note('stock: the template refused ('.($reply['reason'] ?? 'no reply').')');
            $this->close();

            return;
        }

        $this->command = [PHP_BINARY, ...array_slice($argv, 1)];
        $this->writeArgvFile($id, $this->command);

        // The stub script sends `go` once it forwards signals: until then the worker stays parked, and
        // the template kills it when the connection closes. So if the script can't run, this process
        // simply boots stock: no worker was released.
        try {
            $this->becomeStub((int) $reply['pid'], (string) $reply['start'], $id, $ignored);
            self::note('stock: the stub script could not run (no descriptor below 10, or no /bin/sh)');
        } finally {
            @unlink("{$this->runtime}/req-{$id}.argv");
            $this->close();
        }
    }

    /** Whether this key's template is held stock by a marker (a crash breaker, a refusal) for now. */
    private function heldStock(): bool
    {
        $marker = json_decode((string) @file_get_contents("{$this->runtime}/{$this->key16}.stock"), true);

        if (! is_array($marker) || ($marker['until'] ?? 0) <= time()) {
            return false;
        }

        if (($marker['reason'] ?? null) === 'logs-to-stdio') {
            self::note('stock: this app logs to stdout/stderr, which shared-log mode would move; install the PHP FFI extension to fork its workers');
        }

        return true;
    }

    /** @param  list<int>  $ignored */
    private function spawnMessage(string $id, array $argv, array $ignored): array
    {
        $message = [
            't' => 'spawn',
            'id' => $id,
            'pid' => getmypid(),
            'protocol' => Runtime::PROTOCOL,
            'prepend' => (string) ini_get('auto_prepend_file'),
            'stdio' => [self::identity(STDOUT), self::identity(STDERR)],
            'ignored' => $ignored, // PHP hides an inherited ignore from /proc
        ];

        if (PHP_OS_FAMILY !== 'Linux') {
            // No /proc to read them from: the kernel-verified peer's own message is its context.
            $message += [
                'argv' => $argv,
                'entry' => $this->entry,
                'cwd' => (string) getcwd(),
                'env' => getenv(),
                'umask' => umask(),
                'limits' => function_exists('posix_getrlimit') ? (posix_getrlimit() ?: []) : [],
                'nice' => function_exists('pcntl_getpriority') ? @pcntl_getpriority() : null,
            ];
        }

        return $message;
    }

    /** Sends the spawn request with this process's stdio and returns the template's final reply. */
    private function requestWorker(string $json): array
    {
        $this->send($json."\n", true);
        $reply = $this->receiveLine();

        if (($reply['t'] ?? null) === 'waiting') {
            $reply = $this->receiveLine(10.0); // held while a fresh template's first worker warms it
        }

        if (($reply['t'] ?? null) === 'prove') {
            $token = @fopen((string) $reply['token'], 'r'); // open while the template looks for it
            $this->send("{\"t\":\"proved\"}\n");
            $reply = $this->receiveLine();
            is_resource($token) && fclose($token);

            if (($reply['t'] ?? null) === 'waiting') {
                $reply = $this->receiveLine(10.0);
            }
        }

        return $reply;
    }

    private function connectOrLaunch(): bool
    {
        $socket = "{$this->runtime}/{$this->key16}.sock";

        if ($this->connect($socket)) {
            return true;
        }

        $lock = @fopen("{$this->runtime}/{$this->key16}.lock", 'c');

        if ($lock === false || ! self::lock($lock, self::LAUNCH_WAIT + 2.0)) {
            return false;
        }

        try {
            if ($this->connect($socket)) {
                return true; // another stub of this herd launched it meanwhile
            }

            if (file_exists($socket)) {
                // It exists but refuses: replace it only when its template is really gone.
                $pid = explode(' ', trim((string) @file_get_contents("{$this->runtime}/{$this->key16}.pid")), 2);

                if (count($pid) === 2 && Identity::alive((int) $pid[0], $pid[1])) {
                    self::note('stock: the template is alive but not accepting (busy or retiring)');

                    return false;
                }

                @unlink($socket);
                @unlink("{$this->runtime}/{$this->key16}.pid");
            }

            $this->launch();

            // Keep the lock until the template accepts: the rest of the herd then connects to it
            // instead of launching another one.
            $deadline = microtime(true) + self::LAUNCH_WAIT;

            while (microtime(true) < $deadline) {
                if ($this->connect($socket)) {
                    return true;
                }

                usleep(50000);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::note('stock: the template did not start (see '."{$this->runtime}/{$this->key16}.log)");

        return false;
    }

    private function connect(string $socket): bool
    {
        if (! file_exists($socket)) {
            return false;
        }

        if ($this->mode === 'normal') {
            try {
                $this->fd = Native::get()->connect($socket, self::CONNECT_TIMEOUT);

                return true;
            } catch (Throwable) {
                return false;
            }
        }

        $stream = @stream_socket_client('unix://'.$socket, $errno, $error, self::CONNECT_TIMEOUT);

        if ($stream === false) {
            return false;
        }

        stream_set_timeout($stream, (int) self::CONNECT_TIMEOUT);
        $this->stream = $stream;

        return true;
    }

    /** Starts this key's template, detached from this process's session. */
    private function launch(): void
    {
        $template = RuntimeDir::copy($this->runtime, 'template', 'php', (string) file_get_contents(dirname(__DIR__).'/bin/template.php'));
        RuntimeDir::writeAtomic($this->runtime, "{$this->key16}.ini.json", json_encode(TemplateKey::iniValues(), JSON_UNESCAPED_SLASHES));

        $command = [PHP_BINARY, ...self::phpOptions()];

        foreach (TemplateKey::forcedIni($this->settings) as $setting) {
            $command[] = '-d';
            $command[] = $setting;
        }

        $command[] = $template;
        $env = array_merge(getenv(), [
            'QP_SERVER' => 'queue',
            'QP_RUNTIME' => $this->runtime,
            'QP_KEY16' => $this->key16,
            'QP_RELEASE' => $this->release,
            'QP_INI_HASH' => $this->iniHash,
            'QP_OUTPUT' => $this->mode,
            'QP_SETTINGS' => json_encode($this->settings),
            'QP_LOG' => $this->mode === 'shared-log' ? $this->sharedLog() : '',
            'HP_PACKAGE_DIR' => dirname(__DIR__),
        ]);

        $output = $this->mode === 'shared-log' ? $this->sharedLog() : "{$this->runtime}/{$this->key16}.log";
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $output, 'a'], 2 => ['file', $output, 'a']];

        foreach (Audit::openFds() as $fd) {
            if ($fd > 2) {
                $spec[$fd] = ['file', '/dev/null', 'r']; // nothing of this process reaches the template
            }
        }

        $launcher = Process::fork();

        if ($launcher === 0) {
            posix_setsid();
            @proc_open($command, $spec, $pipes, $this->release, $env);
            posix_kill(getmypid(), SIGKILL); // leave without running this process's shutdown code
        }

        if ($launcher > 0) {
            pcntl_waitpid($launcher, $status);
        }
    }

    /** storage/logs/queue-prefork-<host>-<key16>.log. */
    private function sharedLog(): string
    {
        $dir = "{$this->release}/storage/logs";

        return (is_dir($dir) ? $dir : $this->runtime).'/queue-prefork-'.preg_replace('/[^A-Za-z0-9.-]/', '_', (string) gethostname())."-{$this->key16}.log";
    }

    /** Execs into the stub script; returns only when it can't (no descriptor below 10, no /bin/sh). */
    /** @param  list<int>  $ignored  signals the original process ignores, for the stub's stock exec */
    private function becomeStub(int $worker, string $start, string $id, array $ignored): void
    {
        $fd = $this->prepareConnection();

        if ($fd === null || $fd > 9) {
            return; // the script redirects with a single digit
        }

        $script = RuntimeDir::copy($this->runtime, 'stub', 'sh', (string) file_get_contents(dirname(__DIR__).'/bin/stub.sh'));

        if ($this->mode === 'shared-log') {
            @fwrite(STDERR, '[queue-prefork] worker output goes to '.$this->sharedLog()."; install the PHP FFI extension to keep it in this log\n");
        }

        if (function_exists('set_time_limit')) {
            set_time_limit(0); // an armed interval timer would survive the exec and end the shell
        }

        $names = implode(' ', array_map(fn (int $signal) => Signals::IGNORABLE[$signal], $ignored));

        // The stub must be able to trap them, and a shell can't trap a signal ignored on entry, as one
        // ignored with pcntl_signal() would be. A handler still ignores them until the exec resets it.
        foreach ($ignored as $signal) {
            pcntl_signal($signal, static function (): void {});
        }

        @pcntl_exec('/bin/sh', [$script, (string) $worker, $start, $id, (string) $fd, $this->runtime, $names]);

        foreach ($ignored as $signal) {
            pcntl_signal($signal, SIG_IGN); // the exec failed: this process boots stock as it started
        }
    }

    /**
     * The connection becomes the stub's status channel: a low descriptor, blocking, with no handshake
     * timeouts left on it (socket options and file status flags survive exec).
     */
    private function prepareConnection(): ?int
    {
        if ($this->mode === 'normal') {
            $native = Native::get();
            $low = $native->dupFrom($this->fd, 3);

            if ($low !== $this->fd) {
                $native->close($this->fd);
                $this->fd = $low;
            }

            $native->setTimeouts($low, 0);
            $native->setBlocking($low, true);

            return $low;
        }

        stream_set_blocking($this->stream, true);

        return Fd::number($this->stream);
    }

    /** @param list<string> $command */
    private function writeArgvFile(string $id, array $command): void
    {
        RuntimeDir::writeAtomic($this->runtime, "req-{$id}.argv", 'set -- '.implode(' ', array_map('escapeshellarg', $command))."\n");
    }

    private function send(string $data, bool $withStdio = false): void
    {
        if ($this->mode === 'shared-log') {
            fwrite($this->stream, $data);

            return;
        }

        $native = Native::get();
        $sent = $withStdio ? $native->sendWithFds($this->fd, $data, [0, 1, 2]) : $native->send($this->fd, $data);

        while ($sent >= 0 && $sent < strlen($data)) {
            $data = substr($data, $sent);
            $sent = $native->send($this->fd, $data);
        }

        if ($sent < 0) {
            throw new \RuntimeException('send to the template failed');
        }
    }

    /**
     * One JSON line from the template, bounded by $seconds and the connection's timeouts. Replies can
     * arrive together (`waiting` then `spawned`), so what follows the line stays in the inbox.
     */
    private function receiveLine(float $seconds = self::CONNECT_TIMEOUT): array
    {
        $deadline = microtime(true) + $seconds;

        while (! str_contains($this->inbox, "\n") && microtime(true) < $deadline) {
            if ($this->mode === 'shared-log') {
                $chunk = fgets($this->stream);

                if ($chunk === false) {
                    break;
                }

                $this->inbox .= $chunk;

                continue;
            }

            $got = Native::get()->receive($this->fd, 4096, 0);

            if ($got === null) {
                continue;
            }

            if ($got['eof']) {
                break;
            }

            $this->inbox .= $got['data'];
        }

        $end = strpos($this->inbox, "\n");

        if ($end === false) {
            return [];
        }

        $line = substr($this->inbox, 0, $end);
        $this->inbox = (string) substr($this->inbox, $end + 1);

        return (array) json_decode($line, true);
    }

    private function close(): void
    {
        if ($this->fd !== null) {
            Native::get()->close($this->fd);
            $this->fd = null;
        }

        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /** This process's command line as the kernel has it: [php as typed, options…, script, command, args…]. */
    public static function commandLine(): array
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $cmdline = explode("\0", rtrim((string) @file_get_contents('/proc/self/cmdline'), "\0"));

            if ($cmdline !== ['']) {
                return $cmdline;
            }
        }

        return [PHP_BINARY, ...array_map('strval', $_SERVER['argv'] ?? [])];
    }

    /** The PHP options before the script, replayed to start the template with the same ini. */
    public static function phpOptions(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return []; // macOS (development): the template's ini check catches anything lost
        }

        $argv = self::commandLine();
        array_shift($argv);
        $options = [];

        while ($argv !== [] && str_starts_with($argv[0], '-')) {
            $option = array_shift($argv);

            if ($option === '-f' || $option === '--') {
                break; // the script follows; the template runs its own
            }

            $options[] = $option;

            if (in_array($option, CommandLine::PHP_VALUE_OPTIONS, true) && $argv !== []) {
                $options[] = array_shift($argv);
            }
        }

        return $options;
    }

    /** @return array{0: int, 1: int} [dev, ino] of a stream's file, for the stdio-logging check */
    private static function identity($stream): array
    {
        $stat = @fstat($stream) ?: ['dev' => 0, 'ino' => 0];

        return [(int) $stat['dev'], (int) $stat['ino']];
    }

    /** @param resource $lock */
    private static function lock($lock, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        do {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                return true;
            }

            usleep(20000);
        } while (microtime(true) < $deadline);

        return false;
    }

    /** One line to the process manager's log, at most once a minute per message. */
    private static function note(string $message): void
    {
        // Rate-limited through a stamp in the checked 0700 runtime dir (never in a shared /tmp), when there is one.
        $dir = RuntimeDir::shared();

        try {
            RuntimeDir::check($dir);
            $stamp = "{$dir}/note-".substr(md5($message), 0, 12);
        } catch (Throwable) {
            $stamp = null;
        }

        if ($stamp !== null && @filemtime($stamp) > time() - 60) {
            return;
        }

        if ($stamp !== null) {
            @touch($stamp);
        }

        @fwrite(STDERR, '[queue-prefork] '.$message."\n");
    }
}
