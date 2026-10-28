<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * The workbench fixtures under `queue:work`, stock against prefork: what a job sees and how every fixture
 * ends must match, apart from the documented differences (shared opcache, the process title). The
 * Horizon counterpart is StockParityTest.
 */
class QueueWorkStockParityTest extends QueueWorkCase
{
    public function test_a_job_sees_the_same_process_as_in_stock(): void
    {
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch reads-argv --queue='.self::QUEUE));
        $seen = [];

        foreach (self::BOTH as $container) {
            $seen[$container] = self::waitUntil(fn () => self::rows($container, 'ReadsArgv'), 20, "ReadsArgv in {$container}")[0]['value'];
        }

        $comparable = fn (array $value) => array_diff_key($value, array_flip([
            'pid', 'ppid', 'autoload_pid', 'request_time_float', 'laravel_start', 'title', 'opcache_enabled', 'queue_prefork_env',
        ]));

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
        // All but `crashes` (QueueWorkParityTest has it): random values, connections, Concurrency::run,
        // batches, releases, failures, a timeout kill, a --memory exit, output.
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch --queue='.self::QUEUE));
        $outcomes = [];

        foreach (self::BOTH as $container) {
            $outcomes[$container] = self::settledOutcome($container);
        }

        self::waitBoth(fn ($c) => self::shapeProblem($c) === null, 60, 'the programs back after the timeout and memory exits');

        foreach (self::BOTH as $container) {
            $outcomes[$container]['exits'] = $this->newExits($container);
        }

        if (($log = getenv('HP_LIFECYCLE_LOG')) !== false && $log !== '') {
            file_put_contents($log, json_encode(['scenario' => 'every fixture', 'outcomes' => $outcomes])."\n", FILE_APPEND);
        }

        $this->assertNotSame([], $outcomes[self::STOCK]['jobs']);
        $this->assertSame($outcomes[self::STOCK], $outcomes[self::PREFORK]);
    }

    public function test_concurrent_workers_draw_different_random_numbers(): void
    {
        // Forked workers reseed: two of them must not share a sequence (the template's seed). Each job
        // holds its worker for a moment after drawing, so the four go to four workers.
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch reads-random --times=4 --hold=3 --queue='.self::QUEUE));

        foreach (self::BOTH as $container) {
            $rows = self::waitUntil(fn () => count(self::rows($container, 'ReadsRandom')) === 4 ? self::rows($container, 'ReadsRandom') : null, 30, "ReadsRandom in {$container}");
            $this->assertGreaterThan(2, count(array_unique(array_column($rows, 'pid'))), "{$container}: the jobs ran on several workers");

            foreach (['mt_rand', 'rand'] as $generator) { // the seeded ones: a shared seed repeats across workers
                $values = array_map(fn ($row) => $row['value'][$generator], $rows);
                $this->assertSame(count($values), count(array_unique($values)), "{$container}: no two jobs drew the same {$generator}()");
            }
        }
    }

    /** Job names with counts and the failed_jobs count, once nothing has changed for 8 seconds. */
    private static function settledOutcome(string $container): array
    {
        $last = null;
        $stableSince = microtime(true);

        return self::waitUntil(function () use ($container, &$last, &$stableSince) {
            $jobs = [];

            foreach (explode("\n", self::artisan($container, 'fixtures:results --json --limit=1000')) as $line) {
                $row = json_decode($line, true);

                if (is_array($row) && isset($row['job'])) {
                    $jobs[$row['job']] = ($jobs[$row['job']] ?? 0) + 1;
                }
            }

            ksort($jobs);
            $outcome = ['jobs' => $jobs, 'failed' => self::failed($container)];

            if ($outcome !== $last) {
                $last = $outcome;
                $stableSince = microtime(true);

                return null;
            }

            return microtime(true) - $stableSince >= 8 ? $outcome : null;
        }, 180, "{$container}'s fixtures to settle");
    }
}
