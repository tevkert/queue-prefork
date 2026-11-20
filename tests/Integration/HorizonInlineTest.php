<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Real Horizon with its supervisors inside the master (the workbench's App\Horizon classes) and the
 * template forking their workers: the tree, jobs, Horizon's own commands, and the fallbacks. Needs the
 * prefork stack recreated with HORIZON_INLINE=true (docker/README.md).
 */
class HorizonInlineTest extends IntegrationCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (trim(self::exec(self::PREFORK, 'printenv HORIZON_INLINE')[1]) !== 'true') {
            $this->markTestSkipped('Needs the prefork stack with HORIZON_INLINE=true (see docker/README.md)');
        }

        self::ensureHorizonRunning(self::PREFORK);
        self::waitForInlineShape();
        self::artisan(self::PREFORK, 'fixtures:results --reset');
    }

    public function test_the_template_forks_every_supervisors_workers(): void
    {
        $state = self::waitForInlineShape();
        [$master] = self::role($state, 'master');

        $this->assertSame([$master['pid']], array_column($state['masters'], 'pid'), 'Horizon\'s master record is R');
        $this->assertStringContainsString('Horizon is running', self::artisan(self::PREFORK, 'horizon:status'));

        foreach ($state['supervisors'] as $supervisor) {
            $this->assertSame('running', $supervisor['status']);
        }

        foreach (self::role($state, 'worker') as $worker) {
            $this->assertMatchesRegularExpression('#^/usr/local/bin/php artisan(-worker)? horizon:work redis .*--supervisor=#', $worker['cmd'], 'workers keep the stock command line');
        }

        $this->assertSame(0, self::exec(self::PREFORK, 'php artisan queue-prefork:status --no-ansi')[0], 'prefork-status: not degraded');
    }

    public function test_jobs_run_on_workers_the_template_forked(): void
    {
        $state = self::waitForInlineShape();
        [$template] = self::role($state, 'template');
        self::artisan(self::PREFORK, 'fixtures:dispatch reads-argv');
        [$row] = self::waitUntil(fn () => self::results(self::PREFORK, 'ReadsArgv'), 20, 'ReadsArgv to run');
        $seen = $row['value'];

        $this->assertSame($template['pid'], $seen['ppid'], 'the worker is the template\'s child');
        $this->assertSame(['artisan', 'horizon:work'], array_slice($seen['server_argv'], 0, 2));
        $this->assertSame($seen['pid'], $seen['autoload_pid'], 'autoload.files ran in the worker itself');
        $this->assertTrue($seen['opcache_enabled'], 'the worker runs on the shared opcache');
    }

    public function test_pause_and_continue(): void
    {
        self::artisan(self::PREFORK, 'horizon:pause');

        try {
            self::waitUntil(fn () => array_unique(array_column(self::inspect()['supervisors'], 'status')) === ['paused'], 15, 'every supervisor paused');

            self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=3');
            sleep(6);
            $this->assertSame([], self::results(self::PREFORK, 'Succeeds'), 'paused workers take no jobs');
        } finally {
            self::artisan(self::PREFORK, 'horizon:continue');
        }

        self::waitUntil(fn () => count(self::results(self::PREFORK, 'Succeeds')) === 3, 20, 'the 3 jobs after continue');
    }

    public function test_a_killed_worker_is_forked_again(): void
    {
        $state = self::waitForInlineShape();
        [$template] = self::role($state, 'template');
        $name = self::supervisorName($state, 'maintenance');
        [$worker] = self::workersOf($state, $name);

        self::kill(self::PREFORK, $worker['pid']);

        $new = self::waitUntil(function () use ($name, $worker) {
            $workers = self::workersOf(self::inspect(), $name);

            return count($workers) === 1 && $workers[0]['pid'] !== $worker['pid'] ? $workers[0] : null;
        }, 20, "a new {$name} worker");

        $this->assertSame($template['pid'], $new['ppid'], 'forked by the same template');
    }

    public function test_a_worker_the_template_cannot_fork_is_replaced_by_a_stock_one(): void
    {
        $state = self::waitForInlineShape();
        [$master] = self::role($state, 'master');
        [$template] = self::role($state, 'template');
        $name = self::supervisorName($state, 'media');
        [$worker] = self::workersOf($state, $name);

        // Horizon holds back a worker restarted within the last minute, so this kills one no other test does.
        // A soft process limit below what the user already runs makes the template's fork() fail with EAGAIN.
        // Only the template gets it: the master, forked before, keeps its own limit and can still exec.
        [$code, $output] = self::exec(self::PREFORK, "prlimit --pid {$template['pid']} --nproc=1:");
        $this->assertSame(0, $code, "prlimit: {$output}");

        try {
            self::kill(self::PREFORK, $worker['pid']);

            $stock = self::waitUntil(function () use ($name, $master) {
                $workers = self::workersOf(self::inspect(), $name);

                return count($workers) === 1 && $workers[0]['ppid'] === $master['pid'] ? $workers[0] : null;
            }, 30, "a {$name} worker exec'd by the master");

            $this->assertNotSame($worker['pid'], $stock['pid']);

            $unit = self::waitUntil(function () use ($name) {
                foreach (self::inspect()['snapshot']['units'] ?? [] as $unit) {
                    if ($unit['supervisor'] === $name && $unit['kind'] === 'stock') {
                        return $unit;
                    }
                }

                return null;
            }, 15, "the snapshot to list {$name} as stock");

            $this->assertStringStartsWith('worker spawn failed', $unit['reason']);
            $this->assertSame(1, self::exec(self::PREFORK, 'php artisan queue-prefork:status --no-ansi')[0], 'prefork-status reports the stock supervisor');

            $after = self::inspect();

            foreach (array_keys(self::SUPERVISORS) as $other) {
                if ($other !== 'media') {
                    foreach (self::workersOf($after, self::supervisorName($after, $other)) as $sibling) {
                        $this->assertSame($template['pid'], $sibling['ppid'], "{$other}'s workers stay forked");
                    }
                }
            }
        } finally {
            // A new template comes with the restart; the limited one goes.
            self::supervisorctl(self::PREFORK, 'restart horizon');
            self::waitForInlineShape(60);
        }
    }

    public function test_terminate_ends_the_workers_with_the_master(): void
    {
        $state = self::waitForInlineShape();
        $before = [...self::role($state, 'master'), ...self::role($state, 'template'), ...self::role($state, 'worker')];

        self::artisan(self::PREFORK, 'horizon:terminate');

        self::waitUntil(function () use ($before) {
            foreach ($before as $process) {
                if (self::alive(self::PREFORK, $process['pid'], $process['start'])) {
                    return false;
                }
            }

            return true;
        }, 40, 'the master, the template and every worker to exit');

        // supervisord starts Horizon again, in the same shape.
        [$master] = self::role(self::waitForInlineShape(60), 'master');
        [$old] = self::role($state, 'master');
        $this->assertNotSame($old['pid'], $master['pid'], 'a new master');
    }

    /**
     * Supervisors inside the master: one master and one template, no fork server or supervisor process, every
     * supervisor recorded with the master's pid, and at least its minimum workers, all forked by the template.
     */
    private static function inlineShape(array $state): ?string
    {
        $masters = self::role($state, 'master');
        $templates = self::role($state, 'template');

        if (count($masters) !== 1 || count($templates) !== 1) {
            return 'one master with one template';
        }

        if (self::role($state, 'fork-server') !== [] || self::role($state, 'supervisor') !== []) {
            return 'no fork server or supervisor process';
        }

        if (count($state['supervisors']) !== count(self::SUPERVISORS)) {
            return count(self::SUPERVISORS).' supervisor records';
        }

        foreach ($state['supervisors'] as $record) {
            if ($record['pid'] !== $masters[0]['pid']) {
                return "{$record['name']} recorded with the master's pid";
            }
        }

        foreach (self::SUPERVISORS as $name => $minimum) {
            $workers = self::workersOf($state, self::supervisorName($state, $name));
            $forked = array_filter($workers, fn (array $p) => $p['ppid'] === $templates[0]['pid']);

            if (count($workers) < $minimum || count($forked) !== count($workers)) {
                return "{$minimum}+ workers for {$name}, all forked by the template";
            }
        }

        return ($state['snapshot']['mode'] ?? null) === 'prefork' ? null : 'a prefork snapshot';
    }

    private static function waitForInlineShape(float $timeout = 40): array
    {
        $missing = null;

        return self::waitUntil(function () use (&$missing) {
            $state = self::inspect();
            $missing = self::inlineShape($state);

            return $missing === null ? $state : null;
        }, $timeout, function () use (&$missing) {
            return 'the inline tree: '.($missing ?? 'inspect failed');
        });
    }

    private static function supervisorName(array $state, string $suffix): string
    {
        foreach ($state['supervisors'] as $record) {
            if (str_ends_with($record['name'], ':'.$suffix)) {
                return $record['name'];
            }
        }

        return "?:{$suffix}";
    }

    /** @return list<array> */
    private static function workersOf(array $state, string $supervisor): array
    {
        $pattern = '/--supervisor='.preg_quote($supervisor, '/').'(\s|$)/';

        return array_values(array_filter(self::role($state, 'worker'), fn (array $p) => preg_match($pattern, $p['cmd']) === 1));
    }
}
