<?php

namespace Tevkert\QueuePrefork;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\SupervisorOptions;
use Tevkert\QueuePrefork\Client\Broker;
use Tevkert\QueuePrefork\Client\Direct;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Client\Report;
use Tevkert\QueuePrefork\Server\Opcache;
use Tevkert\QueuePrefork\Server\RoleGuard;
use Tevkert\QueuePrefork\Support\Audit;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\TemplateKey;
use Throwable;

/**
 * The prefork side of the Horizon master: preflight, the template, and the supervisor units.
 *
 * The master either spawns its template as a child, or it was itself forked by a host template, the
 * process the process manager started, and attaches to the channel it inherited. Either way it keeps a
 * broker to the template and hands Horizon a forked process for each supervisor, or null so Horizon
 * starts a stock supervisor.
 */
final class Master
{
    /** @var resource|null */
    private $process = null;

    /** @var list<resource> killed templates still to be reaped */
    private array $zombies = [];

    public ?Broker $broker = null;

    public bool $active = false;

    /** Host mode: the template is this master's parent, not its child; when it's gone, the master ends too. */
    private bool $hosted = false;

    private bool $hostGone = false;

    public string $mode = 'stock';

    public ?string $reason = null;

    public array $template = [];

    public int $generation = 0;

    /** @var list<float> */
    private array $crashes = [];

    /** @var array<string, string> supervisor name => reason */
    public array $stockNames = [];

    private bool $allStock = false;

    private float $respawnAt = 0.0;

    private string $runtimeDir = '';

    private string $statusDir = '';

    private float $lastSnapshot = 0.0;

    /** @var array<string, ForkedProcess> supervisor name => current unit handle */
    private array $units = [];

    public function __construct(private Application $app) {}

    public function start(?callable $warn = null): bool
    {
        try {
            $reasons = Preflight::check($this->app);

            if ($reasons === [] && $this->startTemplate()) {
                $this->active = true;
                $this->mode = 'prefork';
                Report::event('PreforkStarted', ['template' => $this->template['pid'] ?? null], 'info');

                return true;
            }

            $reasons = $reasons ?: [$this->reason ?? 'template did not start'];
        } catch (Throwable $e) {
            $reasons = [get_class($e).': '.$e->getMessage()];
        }

        $this->reason = implode('; ', $reasons);

        if (self::forkedByHost()) {
            self::handBack($this->reason);
        }

        $this->publishSnapshot(true);
        Report::event('PreforkUnavailable', ['reasons' => $reasons]);

        if ($warn !== null) {
            $warn('queue-prefork: stock mode ('.$this->reason.')');
        }

        return false;
    }

    /** Called on every MasterSupervisorLooped (and so also while paused). */
    public function tick(): void
    {
        if (! $this->active) {
            return;
        }

        $this->broker?->pump();

        if (! $this->hosted) {
            $this->watchTemplate(); // a host is watched by Wiring (watchHost())
        }

        $this->publishSnapshot();
    }

    /** A forked unit for this supervisor, or null for a stock one. */
    public function unitProcess(SupervisorOptions $options): ?ForkedProcess
    {
        if (! $this->canForkUnits() || isset($this->stockNames[$options->name])) {
            return null;
        }

        $line = $options->toSupervisorCommand();

        try {
            CommandLine::parse($line, ['horizon:supervisor']);
        } catch (Throwable $e) {
            $this->stockNames[$options->name] = $e->getMessage();

            return null;
        }

        $unit = new ForkedProcess($this->broker, 'unit', $line, $options->directory ?? base_path(), true, $this->statusDir);
        $unit->reapGrace = (float) (config('queue-prefork.exit_grace') ?? 15) + 15;
        $unit->onExit = function (ForkedProcess $process, int $status) use ($options): void {
            if ($status === 71 || $this->stockMarker($process)) {
                $this->stockNames[$options->name] = $status === 71 ? 'no hello from the supervisor loop' : 'crash breaker';
                Report::event('UnitStock', ['supervisor' => $options->name, 'reason' => $this->stockNames[$options->name]]);
            }
        };

        return $this->units[$options->name] = $unit;
    }

    public function statusDir(): string
    {
        return $this->statusDir;
    }

    /**
     * Host mode: this master can't run prefork, so the host runs the original command, stock, in its
     * place. Leaves without running any shutdown code.
     */
    public static function handBack(string $reason): never
    {
        try {
            Runtime::$channel->send(['t' => 'stock', 'reason' => $reason]);
            Runtime::$channel->flushBlocking(1.0);
        } catch (Throwable) {
            RoleGuard::shim(70); // the host exits 70 too, and the process manager starts the program again
        }

        RoleGuard::shim(0);
    }

    /** Host mode, on every loop of the master, whether or not it forks its supervisors. */
    public function watchHost(): void
    {
        if ($this->broker?->lost || ! Identity::alive((int) Runtime::$hostPid, Runtime::$hostStart)) {
            $this->hostGone('the template died');
        }
    }

    public function snapshot(): array
    {
        $units = [];

        foreach ($this->units as $name => $unit) {
            $units[] = [
                'supervisor' => $name,
                'kind' => 'forked',
                'state' => $unit->state(),
                'pid' => $unit->getPid(),
                'loop_pid' => $unit->loopPid(),
            ];
        }

        foreach ($this->stockNames as $name => $reason) {
            $units[] = ['supervisor' => $name, 'kind' => 'stock', 'reason' => $reason];
        }

        return [
            'at' => time(),
            'master' => getmypid(),
            'mode' => $this->active ? ($this->allStock ? 'degraded' : 'prefork') : 'stock',
            'reason' => $this->reason,
            'template' => [
                'pid' => $this->templatePid(),
                'hosted' => $this->hosted,
                'generation' => $this->generation,
                'opcache' => $this->template['opcache'] ?? null,
                'threads' => $this->template['threads'] ?? null,
            ],
            'units' => $units,
            'crashes' => count($this->crashes),
            'protocol' => Runtime::PROTOCOL,
        ];
    }

    /**
     * This master's own descriptors above fd 3, each masked with /dev/null in its template. PHP has no
     * close-on-exec, so the template would otherwise inherit the Redis and database sockets, and its
     * descriptor audit would refuse.
     *
     * @return array<int, array{string, string, string}>
     */
    public static function maskedDescriptors(): array
    {
        $masks = [];

        foreach (Audit::openFds() as $fd) {
            if ($fd > 3) {
                $masks[$fd] = ['file', '/dev/null', 'r'];
            }
        }

        return $masks;
    }

    public static function packageDir(): string
    {
        return dirname(__DIR__);
    }

    /** Whether this master was forked by a host template (and so isn't the program's own pid). */
    private static function forkedByHost(): bool
    {
        return Runtime::$role === 'horizon-master';
    }

    private function startTemplate(): bool
    {
        $this->prepareRuntimeDir();

        if (self::forkedByHost()) {
            return $this->attachHost(); // the host waited for fresh caches before forking this master
        }

        usleep((int) (Opcache::freshCacheWait(base_path()) * 1e6));

        return $this->spawnTemplate();
    }

    private function canForkUnits(): bool
    {
        return $this->active && ! $this->allStock && $this->broker !== null && ! $this->broker->lost;
    }

    private function stockMarker(ForkedProcess $process): bool
    {
        return (glob($this->statusDir.'/'.$process->getPid().'-*.stock') ?: []) !== [];
    }

    private function prepareRuntimeDir(): void
    {
        $this->runtimeDir = RuntimeDir::locate(base_path(), MasterSupervisor::basename(), (string) config('app.key'));
        RuntimeDir::ensure($this->runtimeDir);
        RuntimeDir::prune($this->runtimeDir);

        $this->statusDir = $this->runtimeDir.'/'.Identity::key(getmypid(), Identity::startTime(getmypid()));
        RuntimeDir::ensure($this->statusDir);

        if (! self::forkedByHost()) {
            $launcher = (string) file_get_contents(self::packageDir().'/bin/template.php');
            RuntimeDir::writeAtomic($this->runtimeDir, 'template.php', $launcher);
        }
    }

    /** The settings a template applies on this master's behalf (and a hosted master's own exit watchdog). */
    private function templateConfig(): array
    {
        return [
            'exit_grace' => (int) (config('queue-prefork.exit_grace') ?? 15),
            'hello_timeout' => (float) (config('queue-prefork.hello_timeout') ?? 15),
            'spawn_timeout' => (float) (config('queue-prefork.spawn_timeout') ?? 10),
        ];
    }

    /**
     * Host mode: the host forked this master and already sent `ready` on the inherited channel. Its
     * settings and this master's status dir go back in `config`, before any spawn.
     */
    private function attachHost(): bool
    {
        $channel = Runtime::$channel;
        $message = $channel?->receiveOne((float) (config('queue-prefork.template_timeout') ?? 5));

        if ($channel === null || ($message['t'] ?? null) !== 'ready') {
            $this->reason = 'the host template sent no ready';

            return false;
        }

        $this->hosted = true;
        $this->template = $message;
        Runtime::$config = array_merge(Runtime::$config, $this->templateConfig());
        $channel->send(['t' => 'config', 'status_dir' => $this->statusDir, 'config' => $this->templateConfig()]);
        $this->broker = $this->newBroker($channel);

        return true;
    }

    /**
     * Host mode: without its template this master has no way to run units, and the process manager
     * watches the template's pid. End Horizon the stock way (its supervisors are stopped directly,
     * identity checked); the process manager then starts the program again.
     */
    private function hostGone(string $why): void
    {
        if ($this->hostGone) {
            return;
        }

        $this->hostGone = true;
        $this->broker?->markLost();
        Report::event('TemplateGone', ['template' => Runtime::$hostPid, 'reason' => $why]);
        posix_kill(getmypid(), SIGTERM); // Horizon's own handler: terminate
    }

    private function spawnTemplate(): bool
    {
        $this->generation++;
        $process = @proc_open($this->templateCommand(), $this->templateDescriptors(), $pipes, base_path(), $this->templateEnv());

        if (! is_resource($process)) {
            $this->reason = 'could not start the template';

            return false;
        }

        $channel = new Channel($pipes[3]);
        $message = $channel->receiveOne((float) (config('queue-prefork.template_timeout') ?? 5));

        if (($message['t'] ?? null) !== 'ready') {
            $this->reason = match ($message['t'] ?? null) {
                'unsafe' => 'template unsafe: '.implode('; ', (array) $message['reasons']),
                'log' => 'template not ready: '.$message['message'],
                default => 'template not ready',
            };
            @proc_terminate($process, SIGKILL);
            proc_close($process); // reaps now: after a failed start, tick() never runs to reap it later

            return false;
        }

        $this->process = $process;
        $this->template = $message;
        $this->broker = $this->newBroker($channel);

        return true;
    }

    /** @return list<string> */
    private function templateCommand(): array
    {
        $command = [PHP_BINARY];

        foreach ([...TemplateKey::forcedIni(TemplateKey::settings(base_path())), 'hp.pad='.str_repeat('.', 4096)] as $setting) {
            array_push($command, '-d', $setting);
        }

        $command[] = $this->runtimeDir.'/template.php';

        return $command;
    }

    /**
     * The template's stdout and stderr are this master's, so every unit and worker writes to Horizon's
     * log as a stock one does through its parent's pipes: job lines, dump(), STDERR and fatal errors.
     */
    private function templateDescriptors(): array
    {
        $out = defined('STDOUT') && is_resource(STDOUT) ? STDOUT : ['file', '/dev/null', 'w'];
        $err = defined('STDERR') && is_resource(STDERR) ? STDERR : ['file', '/dev/null', 'w'];
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err, 3 => ['socket']] + self::maskedDescriptors();
        ksort($spec);

        return $spec;
    }

    private function templateEnv(): array
    {
        $settings = TemplateKey::settings(base_path()); // from the environment or .env, as for every template

        return array_merge(getenv(), [
            'HP_PACKAGE_DIR' => self::packageDir(),
            'HP_MASTER_PID' => (string) getmypid(),
            'HP_MASTER_START' => (string) Identity::startTime(getmypid()),
            'HP_RUNTIME_DIR' => $this->runtimeDir,
            'HP_STATUS_DIR' => $this->statusDir,
            'HP_GENERATION' => (string) $this->generation,
            'HP_ORIGINAL_PREPEND' => (string) ini_get('auto_prepend_file'),
            'HP_CONFIG' => json_encode([
                ...$this->templateConfig(),
                'thread_audit' => $settings['QUEUE_PREFORK_THREAD_AUDIT'] !== '0',
                'allow_descriptors' => array_values(array_filter(explode(',', $settings['QUEUE_PREFORK_ALLOW_DESCRIPTORS']))),
            ]),
        ]);
    }

    private function newBroker(Channel $channel): Broker
    {
        $broker = new Broker($channel, 'u', (float) (config('queue-prefork.spawn_timeout') ?? 10), 30);
        $broker->onOverdue = fn () => $this->templateHung();
        $broker->onMessage = function (array $message): void {
            if (($message['t'] ?? null) === 'opcache') {
                $this->template['opcache'] = $message['report'] ?? [];
            } elseif (($message['t'] ?? null) === 'log') {
                Report::event('TemplateLog', $message, (string) ($message['level'] ?? 'info'));
            }
        };

        return $broker;
    }

    /** A spawned template: notices its death, reaps killed ones, and respawns it when due. */
    private function watchTemplate(): void
    {
        if (is_resource($this->process) && ! (proc_get_status($this->process)['running'] ?? false)) {
            $this->templateDied();
        }

        foreach ($this->zombies as $key => $zombie) {
            if (! (proc_get_status($zombie)['running'] ?? false)) {
                unset($this->zombies[$key]);
            }
        }

        if ($this->broker === null && ! $this->allStock && $this->respawnAt > 0 && microtime(true) >= $this->respawnAt) {
            $this->respawnAt = 0.0;

            if (! $this->spawnTemplate()) {
                $this->templateDied();
            }
        }
    }

    private function templateDied(): void
    {
        $this->broker?->markLost();
        $this->broker = null;

        if (is_resource($this->process)) {
            $this->zombies[] = $this->process;
        }

        $this->process = null;
        $now = microtime(true);
        $this->crashes = array_values(array_filter($this->crashes, fn (float $at) => $now - $at < 300));
        $this->crashes[] = $now;
        Report::event('TemplateCrashed', ['crashes_in_5_min' => count($this->crashes)]);

        if (count($this->crashes) >= 3) {
            $this->allStock = true;
            Report::event('BreakerTripped', ['scope' => 'template', 'reason' => '3 template crashes in 5 minutes']);
        } else {
            $this->respawnAt = $now + 10;
        }
    }

    private function templateHung(): void
    {
        Report::event('TemplateHung', ['template' => $this->template['pid'] ?? null]);

        if ($this->hosted) {
            // The hung template is this master's parent, the program the process manager watches: end both.
            Direct::kill((int) Runtime::$hostPid, Runtime::$hostStart, SIGKILL);
            $this->hostGone('the template stopped answering');

            return;
        }

        if (is_resource($this->process)) {
            @proc_terminate($this->process, SIGKILL);
            $this->zombies[] = $this->process;
        }

        $this->process = null;
        $this->broker?->markLost();
        $this->broker = null;
        $this->respawnAt = microtime(true) + 1;
    }

    private function templatePid(): ?int
    {
        if ($this->hosted) {
            return $this->hostGone ? null : Runtime::$hostPid;
        }

        return is_resource($this->process) ? ($this->template['pid'] ?? null) : null;
    }

    private function publishSnapshot(bool $force = false): void
    {
        if (! $force && microtime(true) - $this->lastSnapshot < 5) {
            return;
        }

        $this->lastSnapshot = microtime(true);

        try {
            $key = 'prefork:snapshot:'.MasterSupervisor::basename();
            Redis::connection('horizon')->setex($key, 30, json_encode($this->snapshot()));
        } catch (Throwable) {
            // Status is best effort; never disturb the master loop.
        }
    }
}
