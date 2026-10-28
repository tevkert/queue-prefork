<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Real Horizon with the package, end to end: the process tree, Horizon's own commands, failures of
 * each process kind, and the runtime switch.
 */
class HorizonPreforkTest extends IntegrationCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::ensureHorizonRunning(self::PREFORK);
        self::waitForPreforkShape();
        self::artisan(self::PREFORK, 'fixtures:results --reset');
    }

    public function test_the_tree_and_horizon_see_the_fork_servers_as_supervisors(): void
    {
        $state = self::waitForPreforkShape();
        [$master] = self::role($state, 'master');

        $this->assertSame([$master['pid']], array_column($state['masters'], 'pid'), 'Horizon\'s master record is R');
        $this->assertStringContainsString('Horizon is running', self::artisan(self::PREFORK, 'horizon:status'));

        $servers = array_column(self::role($state, 'fork-server'), 'pid');
        sort($servers);
        $recorded = array_column($state['supervisors'], 'pid');
        sort($recorded);
        $this->assertSame($servers, $recorded, 'every supervisor record is a fork server');

        foreach ($state['supervisors'] as $supervisor) {
            $this->assertSame('running', $supervisor['status']);
        }

        foreach (self::role($state, 'worker') as $worker) {
            $this->assertMatchesRegularExpression('#^/usr/local/bin/php artisan(-worker)? horizon:work redis .*--supervisor=#', $worker['cmd'], 'workers keep the stock command line');
        }

        $this->assertSame(0, self::exec(self::PREFORK, 'php artisan queue-prefork:status --no-ansi')[0], 'prefork-status: not degraded');
    }

    public function test_jobs_see_a_stock_worker_identity(): void
    {
        $state = self::waitForPreforkShape();
        self::artisan(self::PREFORK, 'fixtures:dispatch reads-argv');
        [$row] = self::waitUntil(fn () => self::results(self::PREFORK, 'ReadsArgv'), 20, 'ReadsArgv to run');
        $seen = $row['value'];

        $this->assertSame('artisan', $seen['server_argv'][0], 'the entry script');
        $this->assertSame('horizon:work', $seen['server_argv'][1]);
        $this->assertSame($seen['server_argv'], $seen['globals_argv']);
        $this->assertSame(count($seen['server_argv']), $seen['server_argc']);
        $this->assertSame($seen['server_argv'][0], $seen['script_name']);
        $this->assertSame('/repo/workbench', $seen['cwd']);
        $this->assertSame($seen['pid'], $seen['autoload_pid'], 'autoload.files ran in the worker itself');
        $this->assertTrue($seen['opcache_enabled'], 'the worker runs on the shared opcache');
        $this->assertContains($seen['ppid'], array_column(self::role($state, 'fork-server'), 'pid'), 'the worker is a fork server\'s child');
    }

    public function test_pause_and_continue(): void
    {
        self::artisan(self::PREFORK, 'horizon:pause');

        try {
            self::waitUntil(fn () => str_contains(self::artisan(self::PREFORK, 'horizon:status'), 'paused'), 15, 'horizon:status to say paused');
            self::waitUntil(fn () => array_unique(array_column(self::inspect()['supervisors'], 'status')) === ['paused'], 15, 'every supervisor paused');

            self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=3');
            sleep(6);
            $this->assertSame([], self::results(self::PREFORK, 'Succeeds'), 'paused workers take no jobs');
        } finally {
            self::artisan(self::PREFORK, 'horizon:continue');
        }

        self::waitUntil(fn () => count(self::results(self::PREFORK, 'Succeeds')) === 3, 20, 'the 3 jobs after continue');
        self::waitUntil(fn () => str_contains(self::artisan(self::PREFORK, 'horizon:status'), 'running'), 15, 'horizon:status to say running');
    }

    public function test_pause_and_continue_one_supervisor(): void
    {
        $name = self::supervisorName(self::inspect(), 'background');
        self::artisan(self::PREFORK, 'horizon:pause-supervisor '.escapeshellarg($name));

        try {
            self::waitUntil(fn () => self::supervisorStatus($name) === 'paused', 15, "{$name} paused");
            $this->assertSame('running', self::supervisorStatus(self::supervisorName(self::inspect(), 'maintenance')), 'the others keep running');

            self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=2');
            sleep(6);
            $this->assertSame([], self::results(self::PREFORK, 'Succeeds'));
        } finally {
            self::artisan(self::PREFORK, 'horizon:continue-supervisor '.escapeshellarg($name));
        }

        self::waitUntil(fn () => self::supervisorStatus($name) === 'running', 15, "{$name} running");
        self::waitUntil(fn () => count(self::results(self::PREFORK, 'Succeeds')) === 2, 20, 'the 2 jobs after continue');
    }

    public function test_purge_spares_the_tree_and_still_kills_a_real_orphan(): void
    {
        $before = self::waitForPreforkShape();
        self::exec(self::PREFORK, 'nohup php artisan horizon:work redis --queue=hp-orphan --sleep=1 >/dev/null 2>&1 &');
        $orphan = self::waitUntil(fn () => array_values(array_filter(self::role(self::inspect(), 'worker'), fn ($p) => str_contains($p['cmd'], 'hp-orphan')))[0] ?? null, 10, 'the planted orphan');

        $output = self::artisan(self::PREFORK, 'horizon:purge');

        $this->assertMatchesRegularExpression("/Process: {$orphan['pid']}\\b/", $output, 'purge signalled the orphan');
        self::waitUntil(fn () => ! self::alive(self::PREFORK, $orphan['pid'], $orphan['start']), 15, 'the orphan to exit');

        foreach ($before['processes'] as $process) {
            if ($process['role'] !== 'master' && $process['state'] !== 'Z') {
                $this->assertDoesNotMatchRegularExpression("/Process: {$process['pid']}\\b/", $output, "purge left {$process['role']} {$process['pid']} alone");
                $this->assertTrue(self::alive(self::PREFORK, $process['pid'], $process['start']), "{$process['role']} {$process['pid']} survived purge");
            }
        }
    }

    public function test_a_killed_worker_is_replaced(): void
    {
        $state = self::waitForPreforkShape();
        $server = self::serverFor($state, 'maintenance');
        [$worker] = self::childrenOf($state, $server['pid'], 'worker');

        self::kill(self::PREFORK, $worker['pid']);

        $after = self::waitUntil(function () use ($server, $worker) {
            $state = self::inspect();
            $workers = self::childrenOf($state, $server['pid'], 'worker');

            return count($workers) === 1 && $workers[0]['pid'] !== $worker['pid'] ? $state : null;
        }, 20, 'a new maintenance worker under the same fork server');

        $this->assertNull(self::preforkShape($after));
        $this->assertTrue(self::alive(self::PREFORK, $server['pid'], $server['start']), 'the fork server is untouched');
    }

    public function test_a_killed_loop_is_reprovisioned_and_leaves_its_workers_orphaned_as_stock_does(): void
    {
        $state = self::waitForPreforkShape();
        $server = self::serverFor($state, 'maintenance');
        [$loop] = self::childrenOf($state, $server['pid'], 'supervisor');
        [$worker] = self::childrenOf($state, $server['pid'], 'worker');

        self::kill(self::PREFORK, $loop['pid']);

        $after = self::waitUntil(function () use ($server) {
            $state = self::inspect();
            $new = self::serverFor($state, 'maintenance');

            return $new !== null && $new['pid'] !== $server['pid'] && self::preforkShape($state) === null ? $state : null;
        }, 40, 'a new maintenance unit');

        $this->assertFalse(self::alive(self::PREFORK, $server['pid'], $server['start']), 'the old fork server exited with its loop');
        $orphan = array_values(array_filter($after['processes'], fn ($p) => $p['pid'] === $worker['pid']))[0] ?? null;
        $this->assertNotNull($orphan, 'the old worker lives on, as a stock worker does when its supervisor is killed');
        $this->assertNotContains($orphan['ppid'], array_column(self::role($after, 'fork-server'), 'pid'));

        $output = self::artisan(self::PREFORK, 'horizon:purge');
        $this->assertMatchesRegularExpression("/Process: {$worker['pid']}\\b/", $output, 'and horizon:purge cleans it up, as for stock');
        self::waitUntil(fn () => ! self::alive(self::PREFORK, $worker['pid'], $worker['start']), 15, 'the orphan to exit');
    }

    public function test_a_killed_fork_server_ends_its_unit_and_is_reprovisioned(): void
    {
        $state = self::waitForPreforkShape();
        $server = self::serverFor($state, 'maintenance');
        [$loop] = self::childrenOf($state, $server['pid'], 'supervisor');
        [$worker] = self::childrenOf($state, $server['pid'], 'worker');

        self::kill(self::PREFORK, $server['pid']);

        self::waitUntil(fn () => ! self::alive(self::PREFORK, $loop['pid'], $loop['start']), 20, 'the loop to end its unit');
        self::waitUntil(fn () => ! self::alive(self::PREFORK, $worker['pid'], $worker['start']), 20, 'the loop to stop its worker');
        $this->assertFalse(self::alive(self::PREFORK, $server['pid'], $server['start']));
        self::waitUntil(function () use ($server) {
            $state = self::inspect();
            $new = self::serverFor($state, 'maintenance');

            return $new !== null && $new['pid'] !== $server['pid'] && self::preforkShape($state) === null;
        }, 40, 'a new maintenance unit');
    }

    public function test_a_killed_template_is_respawned_while_units_keep_working(): void
    {
        $state = self::waitForPreforkShape();

        if (self::hosted($state)) {
            $this->markTestSkipped('host mode: the template is the program itself (test_a_killed_host_…)');
        }

        [$template] = self::role($state, 'template');
        $servers = self::role($state, 'fork-server');

        self::kill(self::PREFORK, $template['pid']);

        $new = self::waitUntil(fn () => array_values(array_filter(self::role(self::inspect(), 'template'), fn ($p) => $p['pid'] !== $template['pid']))[0] ?? null, 30, 'a new template');
        $this->assertStringContainsString('hp-template:2', $new['cmd']);

        foreach ($servers as $server) {
            $this->assertTrue(self::alive(self::PREFORK, $server['pid'], $server['start']), "fork server {$server['pid']} outlived its template");
        }

        self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=3');
        self::waitUntil(fn () => count(self::results(self::PREFORK, 'Succeeds')) === 3, 20, 'jobs on the orphaned units');

        // horizon:terminate still reaches every orphaned unit (direct, identity-checked signals).
        $old = self::inspect()['processes'];
        self::artisan(self::PREFORK, 'horizon:terminate');

        foreach ($old as $process) {
            if ($process['state'] !== 'Z') {
                self::waitUntil(fn () => ! self::alive(self::PREFORK, $process['pid'], $process['start']), 30, "{$process['role']} {$process['pid']} to exit after terminate");
            }
        }

        self::ensureHorizonRunning(self::PREFORK);
        self::waitForPreforkShape();
    }

    public function test_a_killed_host_ends_horizon_and_supervisord_brings_it_back(): void
    {
        $state = self::waitForPreforkShape();

        if (! self::hosted($state)) {
            $this->markTestSkipped('only in host mode');
        }

        // The host is the program's pid: its death is the program's. The master, left without a template,
        // terminates Horizon (supervisors stopped directly, identity-checked); supervisord restarts it.
        [$host] = self::role($state, 'template');
        self::kill(self::PREFORK, $host['pid']);

        foreach ($state['processes'] as $process) {
            if ($process['state'] !== 'Z') {
                self::waitUntil(fn () => ! self::alive(self::PREFORK, $process['pid'], $process['start']), 40, "{$process['role']} {$process['pid']} to exit after its host died");
            }
        }

        $new = self::waitForPreforkShape(60);
        $this->assertTrue(self::hosted($new));
        self::artisan(self::PREFORK, 'fixtures:results --reset');
        self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=3');
        self::waitUntil(fn () => count(self::results(self::PREFORK, 'Succeeds')) === 3, 30, 'jobs on the new tree');
    }

    public function test_a_hung_host_is_killed_by_its_master_and_supervisord_brings_it_back(): void
    {
        $state = self::waitForPreforkShape();

        if (! self::hosted($state)) {
            $this->markTestSkipped('only in host mode');
        }

        // SIGSTOP the host while the master is responsive: the master's broker gets no pong, SIGKILLs
        // its parent and terminates; supervisord restarts the program.
        [$host] = self::role($state, 'template');
        self::kill(self::PREFORK, $host['pid'], 'STOP');

        try {
            self::waitUntil(fn () => ! self::alive(self::PREFORK, $host['pid'], $host['start']), 90, 'the master to kill its hung host');

            foreach ($state['processes'] as $process) {
                if ($process['state'] !== 'Z') {
                    self::waitUntil(fn () => ! self::alive(self::PREFORK, $process['pid'], $process['start']), 40, "{$process['role']} {$process['pid']} to exit");
                }
            }
        } finally {
            self::kill(self::PREFORK, $host['pid'], 'CONT');
        }

        $this->assertTrue(self::hosted(self::waitForPreforkShape(60)));
    }

    public function test_terminate_stops_everything_and_horizon_comes_back(): void
    {
        $old = self::waitForPreforkShape()['processes'];
        self::artisan(self::PREFORK, 'horizon:terminate');

        foreach ($old as $process) {
            if ($process['state'] !== 'Z') {
                self::waitUntil(fn () => ! self::alive(self::PREFORK, $process['pid'], $process['start']), 30, "{$process['role']} {$process['pid']} to exit");
            }
        }

        self::ensureHorizonRunning(self::PREFORK);
        $new = self::waitForPreforkShape();
        $this->assertNotSame(self::role($new, 'master')[0]['pid'], array_values(array_filter($old, fn ($p) => $p['role'] === 'master'))[0]['pid']);
    }

    public function test_a_group_stop_by_supervisord_is_clean(): void
    {
        $old = self::waitForPreforkShape()['processes'];
        $since = time();
        self::supervisorctl(self::PREFORK, 'restart horizon');

        foreach ($old as $process) {
            if ($process['state'] !== 'Z') {
                self::waitUntil(fn () => ! self::alive(self::PREFORK, $process['pid'], $process['start']), 10, "{$process['role']} {$process['pid']} to exit after the group stop");
            }
        }

        $log = (string) shell_exec('docker logs --since '.$since.' '.self::PREFORK.' 2>&1');
        $this->assertStringNotContainsString('SIGKILL', $log, 'the master stopped within stopwaitsecs');
        self::waitForPreforkShape();
    }

    public function test_the_runtime_switch_turns_prefork_off_and_on(): void
    {
        try {
            $shown = self::artisan(self::PREFORK, 'queue-prefork:switch disable --mode=queue-work');
            $this->assertMatchesRegularExpression('/Horizon \.+ enabled/', $shown, 'one mode off leaves the other on');
            $this->assertMatchesRegularExpression('/queue:work \.+ disabled \(from switch\)/', $shown);

            self::artisan(self::PREFORK, 'queue-prefork:switch disable --mode=horizon');
            self::artisan(self::PREFORK, 'horizon:terminate');

            $stock = self::waitUntil(function () {
                $state = self::inspect();
                $masters = self::role($state, 'master');

                return count($masters) === 1 && count(self::role($state, 'supervisor')) === count(self::SUPERVISORS)
                    && self::role($state, 'template') === [] && in_array($state['snapshot']['mode'] ?? null, [null, 'stock'], true)
                    && count(array_filter(self::role($state, 'supervisor'), fn ($p) => $p['ppid'] === $masters[0]['pid'])) === count(self::SUPERVISORS)
                    ? $state : null;
            }, 60, 'a stock master after disable');

            $this->assertSame([], self::role($stock, 'fork-server'));
        } finally {
            self::artisan(self::PREFORK, 'queue-prefork:switch clear');
            self::artisan(self::PREFORK, 'horizon:terminate');
        }

        self::waitForPreforkShape(60);
    }

    private static function supervisorName(array $state, string $short): string
    {
        foreach ($state['supervisors'] as $supervisor) {
            if (str_ends_with($supervisor['name'], ':'.$short)) {
                return $supervisor['name'];
            }
        }

        self::fail("no supervisor record for {$short}");
    }

    private static function supervisorStatus(string $name): ?string
    {
        foreach (self::inspect()['supervisors'] as $supervisor) {
            if ($supervisor['name'] === $name) {
                return $supervisor['status'];
            }
        }

        return null;
    }

    private static function serverFor(array $state, string $short): ?array
    {
        $pid = null;

        foreach ($state['supervisors'] as $supervisor) {
            if (str_ends_with($supervisor['name'], ':'.$short)) {
                $pid = $supervisor['pid'];
            }
        }

        return array_values(array_filter(self::role($state, 'fork-server'), fn ($p) => $p['pid'] === $pid))[0] ?? null;
    }

    /** @return list<array> */
    private static function childrenOf(array $state, int $pid, string $role): array
    {
        return array_values(array_filter(self::role($state, $role), fn ($p) => $p['ppid'] === $pid));
    }
}
