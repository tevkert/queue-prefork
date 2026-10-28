<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use RuntimeException;
use Tevkert\QueuePrefork\Support\Audit;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\RuntimeDir;

/**
 * Plays the master (R) for the mechanics tests: spawns a real template from bin/template.php and talks
 * the R↔T protocol directly. No Laravel involved.
 */
final class TemplateHarness
{
    /** @var resource */
    public $process;

    public Channel $channel;

    public array $ready;

    public string $runtimeDir;

    public string $statusDir;

    public string $out;

    /** @param  bool  $mask  mask this process's descriptors above fd 3, as Master does */
    public function __construct(array $config = [], ?int $masterPid = null, bool $mask = true)
    {
        $root = dirname(__DIR__, 2);
        $this->runtimeDir = sys_get_temp_dir().'/hp-mech-'.bin2hex(random_bytes(4));
        RuntimeDir::ensure($this->runtimeDir);
        $master = $masterPid ?? getmypid();
        $this->statusDir = $this->runtimeDir.'/'.Identity::key($master, Identity::startTime($master));
        RuntimeDir::ensure($this->statusDir);
        RuntimeDir::writeAtomic($this->runtimeDir, 'template.php', (string) file_get_contents($root.'/bin/template.php'));
        $this->out = $this->runtimeDir.'/events.jsonl';
        touch($this->out);

        $command = [PHP_BINARY, '-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1', '-d', 'opcache.revalidate_freq=0',
            '-d', 'display_errors=stderr', '-d', 'hp.pad='.str_repeat('.', 4096), '-d', 'auto_prepend_file=', $this->runtimeDir.'/template.php'];
        $env = array_merge(getenv(), [
            'HP_PACKAGE_DIR' => $root,
            'HP_MASTER_PID' => (string) $master,
            'HP_MASTER_START' => (string) Identity::startTime($master),
            'HP_RUNTIME_DIR' => $this->runtimeDir,
            'HP_STATUS_DIR' => $this->statusDir,
            'HP_GENERATION' => '1',
            'HP_CONFIG' => json_encode($config + ['hello_timeout' => 15, 'exit_grace' => 15, 'thread_audit' => true]),
            'HP_TEST_OUT' => $this->out,
            'HP_TEST_WORKER' => $root.'/tests/Fixtures/fake-worker.php',
            'HP_TEST_MARKER' => 'template-env',
        ]);

        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', 'php://stderr', 'w'], 3 => ['socket']];

        foreach ($mask ? Audit::openFds() : [] as $fd) {
            if ($fd > 3) {
                $spec[$fd] = ['file', '/dev/null', 'r'];
            }
        }

        ksort($spec);
        $this->process = proc_open($command, $spec, $pipes, $root, $env);
        $this->channel = new Channel($pipes[3]);
        $ready = $this->channel->receiveOne(10);

        if (! in_array($ready['t'] ?? null, ['ready', 'unsafe'], true)) {
            throw new RuntimeException('Template not ready: '.json_encode($ready));
        }

        $this->ready = $ready;
    }

    public function spawnUnit(string $id, string $options, array $env = []): void
    {
        $loop = dirname(__DIR__).'/Fixtures/fake-loop.php';
        $this->channel->send([
            't' => 'spawn',
            'id' => $id,
            'cmd' => "exec php {$loop} horizon:supervisor test:fake redis {$options}",
            'cwd' => dirname(__DIR__, 2),
            'env' => $env + ['HP_TEST_MARKER' => 'requester-env', 'HP_TEST_OUT' => $this->out, 'HP_TEST_WORKER' => dirname(__DIR__).'/Fixtures/fake-worker.php'] + getenv(),
        ]);
    }

    /** Waits for the next message of the given type (others are kept in $seen). */
    public function await(string $type, float $timeout = 10, ?string $id = null): ?array
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $message = $this->channel->receiveOne(0.5);

            if ($message !== null && $message['t'] === $type && ($id === null || ($message['id'] ?? null) === $id)) {
                return $message;
            }
        }

        return null;
    }

    /** @return list<array> */
    public function events(?string $type = null): array
    {
        $rows = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), file($this->out, FILE_IGNORE_NEW_LINES) ?: [])));

        return $type === null ? $rows : array_values(array_filter($rows, fn ($r) => ($r['e'] ?? null) === $type));
    }

    public function waitForEvents(string $type, int $count, float $timeout = 10): array
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $events = $this->events($type);

            if (count($events) >= $count) {
                return $events;
            }

            usleep(100000);
        }

        return $this->events($type);
    }

    public function close(): void
    {
        if (is_resource($this->process)) {
            $status = proc_get_status($this->process);

            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }

            proc_close($this->process);
        }

        exec('rm -rf '.escapeshellarg($this->runtimeDir));
    }
}
