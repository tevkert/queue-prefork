<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Supervisors inside the master, with stock workers and with forked ones: Horizon's pause and continue,
 * terminate and autoscaling done to both containers at the same moment, and compared. Needs both stacks
 * recreated with HORIZON_INLINE=true (docker/README.md).
 */
class HorizonInlineParityTest extends IntegrationCase
{
    private const BOTH = [self::STOCK, self::PREFORK];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::BOTH as $container) {
            if (trim(self::exec($container, 'printenv HORIZON_INLINE')[1]) !== 'true') {
                $this->markTestSkipped('Needs both stacks with HORIZON_INLINE=true (see docker/README.md)');
            }
        }

        foreach (self::BOTH as $container) {
            self::ensureHorizonRunning($container);
        }

        self::waitBoth(fn ($c) => self::shape($c) === null, 90, 'supervisors inside the master, at their minimum workers');

        foreach (self::BOTH as $container) {
            foreach (['notify', 'import'] as $queue) {
                self::exec($container, "php artisan queue:clear redis --queue={$queue} --force --no-ansi");
            }

            self::artisan($container, 'fixtures:results --reset');
        }
    }

    public function test_pause_and_continue_with_jobs_running(): void
    {
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch sleeps --sleep=10 --times=2 --queue=notify'));
        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps.started') === 2, 30, '2 running jobs');

        self::both(fn ($c) => self::artisan($c, 'horizon:pause'));

        try {
            self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=2 --queue=notify'));
            self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 2, 30, 'the running jobs to finish while paused');
            sleep(5);

            $paused = self::outcomes();
            $this->assertSame(0, $paused[self::STOCK]['succeeds'], 'stock takes no new job while paused');
            $this->assertSame(['paused'], array_values(array_unique($paused[self::STOCK]['supervisors'])));
            $this->assertSame($paused[self::STOCK], $paused[self::PREFORK], 'paused: the same in both');
        } finally {
            self::both(fn ($c) => self::artisan($c, 'horizon:continue'));
        }

        self::waitBoth(fn ($c) => self::rows($c, 'Succeeds') === 2, 30, 'the queued jobs after continue');

        $after = self::outcomes();
        $this->assertSame(['running'], array_values(array_unique($after[self::STOCK]['supervisors'])));
        $this->assertSame($after[self::STOCK], $after[self::PREFORK], 'after continue: the same in both');
    }

    public function test_terminate_with_jobs_running(): void
    {
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch sleeps --sleep=8 --times=2 --queue=notify'));
        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps.started') === 2, 30, '2 running jobs');
        $before = [];

        foreach (self::BOTH as $container) {
            $state = self::inspect($container);
            $before[$container] = [...self::role($state, 'master'), ...self::role($state, 'worker')];
        }

        self::both(fn ($c) => self::artisan($c, 'horizon:terminate'));

        self::waitBoth(function ($c) use ($before) {
            foreach ($before[$c] as $process) {
                if (self::alive($c, $process['pid'], $process['start'])) {
                    return false;
                }
            }

            return true;
        }, 60, 'the old master and its workers to exit');
        self::waitBoth(fn ($c) => self::shape($c) === null, 90, 'Horizon to start again');

        $outcomes = self::outcomes();
        $this->assertSame(2, $outcomes[self::STOCK]['finished'], 'stock lets the running jobs finish');
        $this->assertSame($outcomes[self::STOCK], $outcomes[self::PREFORK], 'after terminate: the same in both');
    }

    public function test_autoscaling_up_and_down(): void
    {
        $minimum = self::SUPERVISORS['import'];
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch sleeps --sleep=3 --times=40 --queue=import'));

        $peaks = [];

        foreach (self::BOTH as $container) {
            $peaks[$container] = self::waitUntil(function () use ($container, $minimum) {
                $workers = self::workers($container, 'import');

                return count($workers) > $minimum ? $workers : null;
            }, 60, "{$container}: import to scale above {$minimum} workers");
        }

        $template = self::role(self::inspect(self::PREFORK), 'template')[0]['pid'];
        $this->assertSame([$template], array_values(array_unique(array_column($peaks[self::PREFORK], 'ppid'))), 'the added workers are forked too');

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 40, 180, 'the 40 jobs');
        self::waitBoth(fn ($c) => count(self::workers($c, 'import')) === $minimum, 90, "import to scale back to {$minimum} workers");

        $this->assertSame(self::outcome(self::STOCK), self::outcome(self::PREFORK), 'after scaling: the same in both');
    }

    /** Why $container isn't at rest with its supervisors inside the master, or null. */
    private static function shape(string $container): ?string
    {
        $state = self::inspect($container);
        $masters = self::role($state, 'master');

        if (count($masters) !== 1 || self::role($state, 'supervisor') !== [] || self::role($state, 'fork-server') !== []) {
            return 'one master and no supervisor process';
        }

        if (count($state['supervisors']) !== count(self::SUPERVISORS)) {
            return count(self::SUPERVISORS).' supervisor records';
        }

        foreach ($state['supervisors'] as $record) {
            if ($record['pid'] !== $masters[0]['pid'] || $record['status'] !== 'running') {
                return "{$record['name']} running, recorded with the master's pid";
            }
        }

        // Stock workers are the master's children; forked ones the template's, in every supervisor.
        $templates = self::role($state, 'template');
        $parent = $container === self::PREFORK ? ($templates[0]['pid'] ?? null) : $masters[0]['pid'];

        foreach (self::SUPERVISORS as $name => $minimum) {
            $workers = self::workersIn($state, $name);

            if (count($workers) < $minimum || array_diff(array_column($workers, 'ppid'), [$parent]) !== []) {
                return "{$minimum}+ workers for {$name}, all ".($container === self::PREFORK ? 'forked by the template' : 'exec\'d by the master');
            }
        }

        return null;
    }

    /** @return list<array> */
    private static function workers(string $container, string $supervisor): array
    {
        return self::workersIn(self::inspect($container), $supervisor);
    }

    /** @return list<array> */
    private static function workersIn(array $state, string $supervisor): array
    {
        $pattern = '/--supervisor=\S+:'.preg_quote($supervisor, '/').'(\s|$)/';

        return array_values(array_filter(self::role($state, 'worker'), fn (array $p) => preg_match($pattern, $p['cmd']) === 1));
    }

    /** @return array{started: int, finished: int, succeeds: int, failed: int, supervisors: array<string, string>} */
    private static function outcome(string $container): array
    {
        $statuses = [];

        foreach (self::inspect($container)['supervisors'] as $record) {
            $statuses[preg_replace('/^.*:/', '', $record['name'])] = $record['status'];
        }

        ksort($statuses);

        return [
            'started' => self::rows($container, 'Sleeps.started'),
            'finished' => self::rows($container, 'Sleeps'),
            'succeeds' => self::rows($container, 'Succeeds'),
            'failed' => preg_match('/failed_jobs: (\d+)/', self::artisan($container, 'fixtures:results --limit=0'), $m) ? (int) $m[1] : -1,
            'supervisors' => $statuses,
        ];
    }

    private static function outcomes(): array
    {
        return [self::STOCK => self::outcome(self::STOCK), self::PREFORK => self::outcome(self::PREFORK)];
    }

    private static function rows(string $container, string $job): int
    {
        return count(array_filter(self::results($container, $job), fn ($row) => $row['job'] === $job));
    }

    private static function both(callable $action): void
    {
        foreach (self::BOTH as $container) {
            $action($container);
        }
    }

    private static function waitBoth(callable $check, float $timeout, string $what): void
    {
        foreach (self::BOTH as $container) {
            self::waitUntil(fn () => $check($container), $timeout, "{$container}: {$what}");
        }
    }
}
