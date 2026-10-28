<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Real Horizon in the docker test environment (docker/README.md), driven from the host with
 * `docker exec`. Opt-in: HP_INTEGRATION=1, with the stack up.
 *
 * The prefork container runs the package; the stock container is the same app without it, for the
 * differential tests. Each test leaves Horizon running in its usual shape.
 */
abstract class IntegrationCase extends TestCase
{
    protected const PREFORK = 'queue-prefork-app-prefork-1';

    protected const STOCK = 'queue-prefork-app-stock-1';

    /**
     * The workbench's supervisors (workbench/config/horizon.php) and their minimum
     * workers. background has three queues with a floor of one each.
     */
    protected const SUPERVISORS = ['import' => 2, 'media' => 1, 'background' => 3, 'notify' => 2, 'maintenance' => 1, 'digest' => 1];

    protected function setUp(): void
    {
        if (getenv('HP_INTEGRATION') !== '1') {
            $this->markTestSkipped('Needs the docker test environment: HP_INTEGRATION=1 (see docker/README.md)');
        }
    }

    /** @return array{0: int, 1: string} exit code and combined output */
    protected static function exec(string $container, string $command): array
    {
        $process = proc_open(['docker', 'exec', '-w', '/repo/workbench', $container, 'sh', '-c', $command], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    protected static function artisan(string $container, string $arguments): string
    {
        return self::exec($container, 'php artisan '.$arguments.' --no-ansi')[1];
    }

    protected static function supervisorctl(string $container, string $arguments): string
    {
        return self::exec($container, 'supervisorctl -c /repo/docker/php/supervisord.conf '.$arguments)[1];
    }

    /**
     * @return array{processes: list<array{pid: int, ppid: int, role: string, state: string, start: string, cmd: string}>,
     *               masters: list<array>, supervisors: list<array>, snapshot: ?array}
     */
    protected static function inspect(string $container = self::PREFORK): array
    {
        [$code, $output] = self::exec($container, 'php /repo/tests/Integration/bin/inspect.php');
        $data = json_decode(trim((string) strrchr("\n".trim($output), "\n")), true);

        if ($code !== 0 || ! is_array($data)) {
            self::fail("inspect failed ({$code}): {$output}");
        }

        return $data;
    }

    /** @return list<array> */
    protected static function role(array $state, string $role): array
    {
        return array_values(array_filter($state['processes'], fn (array $p) => $p['role'] === $role && $p['state'] !== 'Z'));
    }

    protected static function alive(string $container, int $pid, string $start): bool
    {
        [, $output] = self::exec($container, "cat /proc/{$pid}/stat 2>/dev/null");
        $after = substr($output, (int) strrpos($output, ')') + 2);
        $fields = explode(' ', trim($after));

        return count($fields) >= 20 && $fields[19] === $start && ! in_array($fields[0], ['Z', 'X'], true);
    }

    protected static function kill(string $container, int $pid, string $signal = 'KILL'): void
    {
        self::exec($container, "kill -{$signal} {$pid}");
    }

    /**
     * Polls $check every half second until it returns something truthy, and returns that.
     */
    protected static function waitUntil(callable $check, float $timeout, string|\Closure $what): mixed
    {
        $deadline = microtime(true) + $timeout;
        $last = null;

        do {
            $last = $check();

            if ($last) {
                return $last;
            }

            usleep(500000);
        } while (microtime(true) < $deadline);

        $what = is_string($what) ? $what : $what();
        self::fail("Timed out after {$timeout}s waiting for: {$what}");
    }

    /**
     * The full prefork shape: one master, one template under it, one fork server per supervisor under
     * the template with exactly one loop each, at least the minimum workers under each fork server,
     * and Horizon's supervisor records pointing at the fork servers.
     */
    protected static function preforkShape(array $state): ?string
    {
        $masters = self::role($state, 'master');
        $templates = self::role($state, 'template');

        // The template is the master's child, or (host mode) its parent.
        if (count($masters) !== 1 || count($templates) !== 1 || ($templates[0]['ppid'] !== $masters[0]['pid'] && ! self::hosted($state))) {
            return 'one master with one template';
        }

        $servers = array_filter(self::role($state, 'fork-server'), fn ($p) => $p['ppid'] === $templates[0]['pid']);
        $records = array_column($state['supervisors'], 'pid', 'name');

        foreach (self::SUPERVISORS as $name => $minimum) {
            $record = null;

            foreach ($records as $recordName => $pid) {
                if (str_ends_with($recordName, ':'.$name)) {
                    $record = $pid;
                }
            }

            $server = array_values(array_filter($servers, fn ($p) => $p['pid'] === $record));

            if ($server === []) {
                return "a fork server recorded for {$name}";
            }

            $loops = array_filter(self::role($state, 'supervisor'), fn ($p) => $p['ppid'] === $record);
            $workers = array_filter(self::role($state, 'worker'), fn ($p) => $p['ppid'] === $record);

            if (count($loops) !== 1 || count($workers) < $minimum) {
                return "one loop and {$minimum}+ workers under {$name}'s fork server";
            }
        }

        if (count($servers) !== count(self::SUPERVISORS) || ($state['snapshot']['mode'] ?? null) !== 'prefork') {
            return 'exactly '.count(self::SUPERVISORS).' fork servers and a prefork snapshot';
        }

        return null;
    }

    /** Host mode: the master was forked by its template, which is the program supervisord started. */
    protected static function hosted(array $state): bool
    {
        $masters = self::role($state, 'master');
        $templates = self::role($state, 'template');

        return count($masters) === 1 && count($templates) === 1 && $masters[0]['ppid'] === $templates[0]['pid'];
    }

    protected static function waitForPreforkShape(float $timeout = 40): array
    {
        $missing = null;

        return self::waitUntil(function () use (&$missing) {
            $state = self::inspect();
            $missing = self::preforkShape($state);

            return $missing === null ? $state : null;
        }, $timeout, function () use (&$missing) {
            return 'the prefork tree: '.($missing ?? 'inspect failed');
        });
    }

    /** The stock shape: one master, every supervisor under it, at least the minimum workers. */
    protected static function stockShape(array $state): ?string
    {
        $masters = self::role($state, 'master');

        if (count($masters) !== 1) {
            return 'one master';
        }

        $supervisors = array_filter(self::role($state, 'supervisor'), fn ($p) => $p['ppid'] === $masters[0]['pid']);

        if (count($supervisors) !== count(self::SUPERVISORS) || count($state['supervisors']) !== count(self::SUPERVISORS)) {
            return count(self::SUPERVISORS).' supervisors under the master, and their records';
        }

        $workers = array_filter(self::role($state, 'worker'), fn ($p) => in_array($p['ppid'], array_column($supervisors, 'pid'), true));

        return count($workers) >= array_sum(self::SUPERVISORS) ? null : array_sum(self::SUPERVISORS).'+ workers under the supervisors';
    }

    protected static function waitForStockShape(float $timeout = 40): array
    {
        $missing = null;

        return self::waitUntil(function () use (&$missing) {
            $state = self::inspect(self::STOCK);
            $missing = self::stockShape($state);

            return $missing === null ? $state : null;
        }, $timeout, function () use (&$missing) {
            return 'the stock tree: '.($missing ?? 'inspect failed');
        });
    }

    protected static function ensureHorizonRunning(string $container): void
    {
        if (! str_contains(self::supervisorctl($container, 'status horizon'), 'RUNNING')) {
            self::supervisorctl($container, 'start horizon');
        }
    }

    /** @return list<array> job_results rows, oldest first */
    protected static function results(string $container, string $job = ''): array
    {
        $output = self::artisan($container, 'fixtures:results --json --limit=1000'.($job !== '' ? ' --job='.escapeshellarg($job) : ''));

        return array_values(array_filter(array_map(fn ($line) => json_decode($line, true), explode("\n", $output)), 'is_array'));
    }
}
