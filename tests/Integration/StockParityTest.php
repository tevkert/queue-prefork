<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * The same fixtures in the stock and the prefork container: what a job sees and what it leaves
 * behind must match, apart from the documented differences (shared opcache, the process title).
 */
class StockParityTest extends IntegrationCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::STOCK, self::PREFORK] as $container) {
            self::ensureHorizonRunning($container);
            self::artisan($container, 'fixtures:results --reset');
        }

        self::waitForPreforkShape();
        self::waitForStockShape();
    }

    public function test_a_job_sees_the_same_process_as_in_stock(): void
    {
        $seen = [];

        foreach ([self::STOCK, self::PREFORK] as $container) {
            self::artisan($container, 'fixtures:dispatch reads-argv');
            [$row] = self::waitUntil(fn () => self::results($container, 'ReadsArgv'), 20, "ReadsArgv in {$container}");
            $seen[$container] = $row['value'];
        }

        $comparable = function (array $value): array {
            if (is_array($value['prepend'] ?? null)) {
                // compose.prepend.yml: the prepend ran in this very process, not that it had a given pid
                $value['prepend']['pid'] = $value['prepend']['pid'] === $value['pid'];
            }

            $value = array_diff_key($value, array_flip([
                'pid', 'ppid', 'autoload_pid', 'request_time_float', 'laravel_start', 'title', 'opcache_enabled', 'queue_prefork_env',
            ]));

            return json_decode(preg_replace('/[\w.-]+-\w{4}:(?=[a-z]+\b)/', 'HOST:', json_encode($value)), true);
        };

        $this->assertSame($comparable($seen[self::STOCK]), $comparable($seen[self::PREFORK]));

        foreach ($seen as $container => $value) {
            $this->assertSame($value['pid'], $value['autoload_pid'], "{$container}: autoload.files ran in the worker");
            $this->assertLessThan(1.0, abs($value['laravel_start'] - $value['request_time_float']), "{$container}: REQUEST_TIME is the worker's own start, not the template's");
        }

        $this->assertFalse($seen[self::STOCK]['opcache_enabled'], 'stock CLI runs without opcache, as on Forge');
        $this->assertTrue($seen[self::PREFORK]['opcache_enabled']);
    }

    public function test_every_fixture_ends_as_in_stock(): void
    {
        foreach ([self::STOCK, self::PREFORK] as $container) {
            self::artisan($container, 'fixtures:dispatch');
        }

        $outcomes = [];

        foreach ([self::STOCK, self::PREFORK] as $container) {
            $outcomes[$container] = self::settledOutcome($container);
        }

        $this->assertNotSame([], $outcomes[self::STOCK]['jobs']);
        $this->assertSame($outcomes[self::STOCK], $outcomes[self::PREFORK]);
    }

    public function test_worker_output_reaches_the_horizon_log(): void
    {
        foreach ([self::STOCK, self::PREFORK] as $container) {
            $since = time();
            self::artisan($container, 'fixtures:dispatch dumps');
            [$row] = self::waitUntil(fn () => self::results($container, 'Dumps'), 20, "Dumps in {$container}");
            $marker = $row['value']['marker'];

            $log = self::waitUntil(function () use ($container, $since, $marker) {
                $log = (string) shell_exec('docker logs --since '.$since.' '.$container.' 2>&1');

                return substr_count($log, $marker) >= 2 ? $log : null;
            }, 10, "the dump() and STDERR lines in {$container}'s log");

            $this->assertStringContainsString("Dumps fixture stderr {$marker}", $log, $container);
            $this->assertMatchesRegularExpression('/App\\\\Jobs\\\\Dumps .*DONE/', $log, "{$container}: the worker's job lines");
        }
    }

    public function test_a_killed_supervisor_orphans_its_workers_in_stock_too(): void
    {
        // The oracle for HorizonPreforkTest's killed-loop case.
        $state = self::waitForStockShape();
        $supervisor = array_values(array_filter(self::role($state, 'supervisor'), fn ($p) => str_contains($p['cmd'], ':maintenance ')))[0];
        [$worker] = array_values(array_filter(self::role($state, 'worker'), fn ($p) => $p['ppid'] === $supervisor['pid']));

        self::kill(self::STOCK, $supervisor['pid']);
        self::waitUntil(function () use ($supervisor) {
            $state = self::inspect(self::STOCK);

            return count(array_filter(self::role($state, 'supervisor'), fn ($p) => str_contains($p['cmd'], ':maintenance ') && $p['pid'] !== $supervisor['pid'])) === 1;
        }, 40, 'a new stock maintenance supervisor');

        $this->assertTrue(self::alive(self::STOCK, $worker['pid'], $worker['start']), 'the stock worker outlives its killed supervisor');

        $output = self::artisan(self::STOCK, 'horizon:purge');
        $this->assertMatchesRegularExpression("/Process: {$worker['pid']}\\b/", $output);
        self::waitUntil(fn () => ! self::alive(self::STOCK, $worker['pid'], $worker['start']), 15, 'the stock orphan to exit');
    }

    /** Job names with counts and the failed_jobs count, once nothing has changed for 8 seconds. */
    private static function settledOutcome(string $container): array
    {
        $last = null;
        $stableSince = microtime(true);

        return self::waitUntil(function () use ($container, &$last, &$stableSince) {
            $jobs = array_count_values(array_column(self::results($container), 'job'));
            ksort($jobs);
            preg_match('/failed_jobs: (\d+)/', self::artisan($container, 'fixtures:results --limit=0'), $m);
            $outcome = ['jobs' => $jobs, 'failed' => (int) ($m[1] ?? -1)];

            if ($outcome !== $last) {
                $last = $outcome;
                $stableSince = microtime(true);

                return null;
            }

            return microtime(true) - $stableSince >= 8 ? $outcome : null;
        }, 120, "{$container}'s fixtures to settle");
    }
}
