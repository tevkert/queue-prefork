<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Horizon's lifecycle operations and failures, done to the stock and the prefork container at the same
 * moment with jobs in flight, and compared: what happened to the running jobs (finished, failed, run
 * twice), what Horizon reports afterwards, which processes are left, and how the tree recovers.
 *
 * Jobs run on the `notify` queue (a fixed pool of 2). A killed or crashed job stays reserved until the
 * queue's retry_after (180 s in docker/compose.yml) and is then failed, so those tests take minutes.
 *
 * Set HP_LIFECYCLE_LOG to a file to append each scenario's outcomes as JSON lines.
 */
class LifecycleParityTest extends IntegrationCase
{
    private const BOTH = [self::STOCK, self::PREFORK];

    private const QUEUE = 'notify';

    /** retry_after (180 s) plus a margin for the failing worker to pick the job up. */
    private const RETRY_WAIT = 240;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::BOTH as $container) {
            self::ensureHorizonRunning($container);
        }

        self::waitForStockShape(90);
        self::waitForPreforkShape(90);

        // Orphaned workers from an earlier test would compete for this test's jobs.
        self::both(fn ($c) => self::artisan($c, 'horizon:purge'));
        self::waitBoth(fn ($c) => self::outcome($c)['orphans'] === 0, 30, 'no orphaned workers');

        foreach (self::BOTH as $container) {
            self::exec($container, 'php artisan queue:clear redis --queue='.self::QUEUE.' --force --no-ansi');
            self::artisan($container, 'fixtures:results --reset');
        }
    }

    public function test_terminate_with_jobs_running(): void
    {
        $old = self::startSleepers(12);
        self::both(fn ($c) => self::artisan($c, 'horizon:terminate'));

        $this->settleAndCompare('terminate', $old, finished: 2);
    }

    public function test_terminate_wait_with_jobs_running(): void
    {
        $old = self::startSleepers(12);
        self::both(fn ($c) => self::artisan($c, 'horizon:terminate --wait'));

        $this->settleAndCompare('terminate --wait', $old, finished: 2);
    }

    public function test_pause_and_continue_with_jobs_running(): void
    {
        self::startSleepers(10);
        self::both(fn ($c) => self::artisan($c, 'horizon:pause'));
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=2 --queue='.self::QUEUE));

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 2, 30, 'the running jobs to finish while paused');
        sleep(5);
        $paused = self::outcomes();
        $this->assertSame(0, $paused[self::STOCK]['succeeds'], 'stock takes no new job while paused');
        $this->assertSame($paused[self::STOCK], $paused[self::PREFORK], 'paused: the same in both');
        $this->assertSame(['paused'], array_values(array_unique($paused[self::PREFORK]['supervisors'])));

        self::both(fn ($c) => self::artisan($c, 'horizon:continue'));
        self::waitBoth(fn ($c) => self::rows($c, 'Succeeds') === 2, 30, 'the queued jobs after continue');

        $this->settleAndCompare('pause, continue', [], finished: 2, succeeds: 2);
    }

    public function test_pause_and_continue_one_supervisor_with_jobs_running(): void
    {
        self::startSleepers(10);
        self::both(fn ($c) => self::artisan($c, 'horizon:pause-supervisor '.escapeshellarg(self::recordName($c, self::QUEUE))));
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=2 --queue='.self::QUEUE));

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 2, 30, 'the running jobs to finish while paused');
        sleep(5);
        $paused = self::outcomes();
        $this->assertSame(0, $paused[self::STOCK]['succeeds']);
        $this->assertSame($paused[self::STOCK], $paused[self::PREFORK]);
        $this->assertSame('paused', $paused[self::PREFORK]['supervisors'][self::QUEUE]);

        self::both(fn ($c) => self::artisan($c, 'horizon:continue-supervisor '.escapeshellarg(self::recordName($c, self::QUEUE))));
        self::waitBoth(fn ($c) => self::rows($c, 'Succeeds') === 2, 30, 'the queued jobs after continue');

        $this->settleAndCompare('pause-supervisor, continue-supervisor', [], finished: 2, succeeds: 2);
    }

    public function test_restart_all_supervisors_with_jobs_running(): void
    {
        // SIGUSR1 to the master: every supervisor ends with status 1 and Horizon provisions it again.
        $old = self::startSleepers(12);
        self::both(fn ($c) => self::kill($c, self::masterPid($c), 'USR1'));

        $this->settleAndCompare('restart (USR1 to the master)', $old, finished: 2, replaced: ['supervisors', 'workers']);
    }

    public function test_term_to_a_supervisor_ends_it_for_good(): void
    {
        // A supervisor that exits 0 is not provisioned again (dontRestartOn), in stock and in prefork.
        self::startSleepers(10);
        self::both(fn ($c) => self::kill($c, self::recordPid($c, self::QUEUE), 'TERM'));

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 2, 30, 'the running jobs to finish');
        self::waitBoth(fn ($c) => ! array_key_exists(self::QUEUE, self::outcome($c)['supervisors']), 30, 'the supervisor record to go');
        sleep(20); // long enough for any reprovisioning to show up
        $after = self::outcomes();
        self::log('TERM to the notify supervisor', $after);

        $this->assertArrayNotHasKey(self::QUEUE, $after[self::STOCK]['supervisors'], 'stock does not restart it');
        $this->assertSame($after[self::STOCK], $after[self::PREFORK]);

        self::both(fn ($c) => self::artisan($c, 'horizon:terminate')); // put it back for the next test
    }

    public function test_kill_a_supervisor_with_jobs_running(): void
    {
        // SIGKILL to the recorded supervisor pid: stock's supervisor, prefork's fork server. Stock's
        // workers are orphaned and keep polling until purged; prefork's loop ends the unit and stops
        // its workers once their jobs are done. The jobs finish in both.
        self::startSleepers(12);
        $victims = [self::STOCK => self::processes(self::STOCK, [self::recordPid(self::STOCK, self::QUEUE)]),
            self::PREFORK => self::processes(self::PREFORK, [self::recordPid(self::PREFORK, self::QUEUE), self::loopPid(self::QUEUE)])];
        self::both(fn ($c) => self::kill($c, self::recordPid($c, self::QUEUE)));

        $after = $this->settle($victims);
        self::log('KILL the recorded supervisor pid', $after);

        $this->assertSame(2, $after[self::STOCK]['orphans'], 'stock: the workers live on as orphans');
        $this->assertSame(0, $after[self::PREFORK]['orphans'], 'prefork: the unit\'s workers stopped');
        $this->assertSame(self::without($after[self::STOCK], 'orphans'), self::without($after[self::PREFORK], 'orphans'));
        $this->assertSame(['started' => 2, 'finished' => 2, 'failed' => 0], self::jobs($after[self::PREFORK]));

        self::artisan(self::STOCK, 'horizon:purge');
        self::waitUntil(fn () => self::outcome(self::STOCK)['orphans'] === 0, 20, 'purge to clear the stock orphans');
    }

    public function test_kill_the_supervisor_loop_with_jobs_running(): void
    {
        // What `kill -9` on a "horizon:supervisor" process from ps hits in prefork: the loop. Its fork
        // server exits with it and the workers are orphaned, exactly as when stock's supervisor dies.
        self::startSleepers(12);
        $victims = [self::STOCK => self::processes(self::STOCK, [self::recordPid(self::STOCK, self::QUEUE)]),
            self::PREFORK => self::processes(self::PREFORK, [self::recordPid(self::PREFORK, self::QUEUE), self::loopPid(self::QUEUE)])];
        self::kill(self::STOCK, self::recordPid(self::STOCK, self::QUEUE));
        self::kill(self::PREFORK, self::loopPid(self::QUEUE));

        $after = $this->settle($victims);
        self::log('KILL the supervisor loop', $after);

        $this->assertSame(2, $after[self::STOCK]['orphans']);
        $this->assertSame($after[self::STOCK], $after[self::PREFORK]);

        self::both(fn ($c) => self::artisan($c, 'horizon:purge'));
        self::waitBoth(fn ($c) => self::outcome($c)['orphans'] === 0, 20, 'purge to clear the orphans');
    }

    public function test_kill_a_worker_mid_job(): void
    {
        self::startSleepers(30);

        foreach (self::BOTH as $container) {
            $victim = self::results($container, 'Sleeps.started')[0]['pid'];
            self::kill($container, (int) $victim);
        }

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') === 1, 45, 'the other job to finish');
        self::waitBoth(fn ($c) => self::failed($c) === 1, self::RETRY_WAIT, 'the killed job to fail after retry_after');

        $this->settleAndCompare('KILL a worker mid-job', [], finished: 1, failed: 1);
    }

    public function test_crashing_jobs(): void
    {
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch crashes --crash=exit --queue='.self::QUEUE));
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch crashes --crash=segv --queue='.self::QUEUE));

        self::waitBoth(fn ($c) => self::failed($c) === 2, self::RETRY_WAIT, 'both crashed jobs to fail after retry_after');

        $after = $this->settle([]);
        self::log('crashing jobs (exit, SIGSEGV)', $after);
        $this->assertSame(2, self::rows(self::STOCK, 'Crashes'), 'each crashed job ran once');
        $this->assertSame(self::rows(self::STOCK, 'Crashes'), self::rows(self::PREFORK, 'Crashes'));
        $this->assertSame($after[self::STOCK], $after[self::PREFORK]);
    }

    public function test_kill_the_master_with_jobs_running(): void
    {
        // supervisord restarts the master; the old supervisors see their parent gone and terminate.
        $old = self::startSleepers(12);
        self::both(fn ($c) => self::kill($c, self::masterPid($c)));

        $this->settleAndCompare('KILL the master', $old, finished: 2);
    }

    public function test_supervisord_restart_with_jobs_running(): void
    {
        // As a deploy with supervisorctl restart does it: TERM (to the group with stopasgroup), SIGKILL
        // to the group if the master outlives stopwaitsecs (5 s). Whatever stock does to the running jobs
        // (finish, or killed and failed after retry_after), prefork must do the same.
        $old = self::startSleepers(15);
        self::both(fn ($c) => self::supervisorctl($c, 'restart horizon'));

        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') + self::failed($c) === 2, self::RETRY_WAIT, 'both jobs to finish or fail');
        $after = $this->settle($old);
        self::log('supervisorctl restart', $after);

        $this->assertSame(2, $after[self::STOCK]['started'], 'no job ran twice');
        $this->assertSame($after[self::STOCK], $after[self::PREFORK]);
    }

    /**
     * Waits for both trees to recover and every old process to be gone (orphans included), then
     * compares the outcomes and checks the job counts.
     *
     * @param  array<string, list<array>>  $old  container => processes before the action
     * @param  list<string>  $replaced  roles whose old processes must all be gone
     */
    private function settleAndCompare(string $scenario, array $old, int $finished, int $failed = 0, int $succeeds = 0, array $replaced = ['all']): void
    {
        $after = $this->settle($old, $replaced);
        self::log($scenario, $after);

        $this->assertSame(['started' => 2, 'finished' => $finished, 'failed' => $failed], self::jobs($after[self::STOCK]), "{$scenario}: stock");
        $this->assertSame($succeeds, $after[self::STOCK]['succeeds']);
        $this->assertSame($after[self::STOCK], $after[self::PREFORK], "{$scenario}: prefork matches stock");
    }

    /** @return array<string, array> container => outcome, once both trees are whole again */
    private function settle(array $old, array $replaced = ['all']): array
    {
        $started = microtime(true);
        self::waitForStockShape(90);
        self::waitForPreforkShape(90);

        // Jobs still running in old workers (orphans included) finish first.
        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps') + self::failed($c) >= min(2, self::rows($c, 'Sleeps.started')) || microtime(true) - $started > 40, 60, 'in-flight jobs to settle');

        foreach ($old as $container => $processes) {
            foreach ($processes as $process) {
                if ($process['state'] !== 'Z' && ($replaced === ['all'] || in_array($process['role'], array_map(fn ($r) => rtrim($r, 's'), $replaced), true))) {
                    self::waitUntil(fn () => ! self::alive($container, $process['pid'], $process['start']), 60, "{$container}: old {$process['role']} {$process['pid']} to exit");
                }
            }
        }

        sleep(3);

        return self::outcomes();
    }

    /** Dispatches 2 sleeping jobs to both containers and waits until all 4 run. */
    private static function startSleepers(int $seconds): array
    {
        self::both(fn ($c) => self::artisan($c, "fixtures:dispatch sleeps --sleep={$seconds} --times=2 --queue=".self::QUEUE));
        self::waitBoth(fn ($c) => self::rows($c, 'Sleeps.started') === 2, 30, '2 running jobs');

        return [self::STOCK => self::inspect(self::STOCK)['processes'], self::PREFORK => self::inspect(self::PREFORK)['processes']];
    }

    /** @return array{started: int, finished: int, succeeds: int, failed: int, orphans: int, supervisors: array<string, string>} */
    private static function outcome(string $container): array
    {
        $state = self::inspect($container);
        $statuses = [];

        foreach ($state['supervisors'] as $supervisor) {
            $statuses[preg_replace('/^.*:/', '', $supervisor['name'])] = $supervisor['status'];
        }

        ksort($statuses);

        return [
            'started' => self::rows($container, 'Sleeps.started'),
            'finished' => self::rows($container, 'Sleeps'),
            'succeeds' => self::rows($container, 'Succeeds'),
            'failed' => self::failed($container),
            'orphans' => count(array_filter(self::role($state, 'worker'), fn ($p) => $p['ppid'] === 1)),
            'supervisors' => $statuses,
        ];
    }

    private static function outcomes(): array
    {
        return [self::STOCK => self::outcome(self::STOCK), self::PREFORK => self::outcome(self::PREFORK)];
    }

    private static function jobs(array $outcome): array
    {
        return ['started' => $outcome['started'], 'finished' => $outcome['finished'], 'failed' => $outcome['failed']];
    }

    private static function without(array $outcome, string $key): array
    {
        unset($outcome[$key]);

        return $outcome;
    }

    private static function rows(string $container, string $job): int
    {
        return count(array_filter(self::results($container, $job), fn ($row) => $row['job'] === $job));
    }

    private static function failed(string $container): int
    {
        return preg_match('/failed_jobs: (\d+)/', self::artisan($container, 'fixtures:results --limit=0'), $m) ? (int) $m[1] : -1;
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

    /** @param  list<int>  $pids */
    private static function processes(string $container, array $pids): array
    {
        return array_values(array_filter(self::inspect($container)['processes'], fn ($p) => in_array($p['pid'], $pids, true)));
    }

    private static function masterPid(string $container): int
    {
        return self::role(self::inspect($container), 'master')[0]['pid'];
    }

    private static function recordName(string $container, string $short): string
    {
        foreach (self::inspect($container)['supervisors'] as $supervisor) {
            if (str_ends_with($supervisor['name'], ':'.$short)) {
                return $supervisor['name'];
            }
        }

        self::fail("{$container}: no supervisor record for {$short}");
    }

    /** The pid Horizon records for the supervisor: stock's supervisor process, prefork's fork server. */
    private static function recordPid(string $container, string $short): int
    {
        foreach (self::inspect($container)['supervisors'] as $supervisor) {
            if (str_ends_with($supervisor['name'], ':'.$short)) {
                return $supervisor['pid'];
            }
        }

        self::fail("{$container}: no supervisor record for {$short}");
    }

    private static function loopPid(string $short): int
    {
        $server = self::recordPid(self::PREFORK, $short);
        $loops = array_values(array_filter(self::role(self::inspect(self::PREFORK), 'supervisor'), fn ($p) => $p['ppid'] === $server));

        return $loops[0]['pid'];
    }

    private static function log(string $scenario, array $outcomes): void
    {
        $file = getenv('HP_LIFECYCLE_LOG');

        if ($file) {
            file_put_contents($file, json_encode(['scenario' => $scenario, 'variant' => getenv('HP_VARIANT') ?: null] + $outcomes)."\n", FILE_APPEND);
        }
    }
}
