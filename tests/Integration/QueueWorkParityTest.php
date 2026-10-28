<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * The process manager's operations on `queue:work` programs, done to the stock and the prefork container
 * at the same moment with jobs in flight, and the outcomes compared (QueueWorkCase has the setup).
 */
class QueueWorkParityTest extends QueueWorkCase
{
    public function test_forked_workers_look_like_stock_ones_and_write_to_their_programs_logs(): void
    {
        $template = self::template(self::PREFORK);
        $stock = self::workers(self::STOCK);

        foreach (self::workers(self::PREFORK) as $worker) {
            $this->assertSame($template['pid'], $worker['ppid'], 'workers are the template\'s children');
            $this->assertSame($worker['pid'], $worker['pgid'], 'each leads its own process group');
            $this->assertSame($stock[0]['cmd'], $worker['cmd'], 'and shows the stock command line');
        }

        foreach (self::programs(self::PREFORK) as $name => $pid) {
            $stub = self::process(self::PREFORK, $pid);
            $this->assertTrue(self::isStub($stub), "{$name}'s pid is its stub");

            // Below 10 (the shells keep their script higher), only stdio and the status connection.
            $connection = (int) explode(' ', $stub['cmd'])[5];
            $low = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim(self::exec(self::PREFORK, "ls /proc/{$pid}/fd")[1]))), fn ($fd) => $fd < 10));
            sort($low);
            $this->assertSame([0, 1, 2, $connection], $low, "{$name}'s stub holds no stray descriptor");
        }

        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch dumps --queue='.self::QUEUE));
        self::waitBoth(fn ($c) => self::rows($c, 'Dumps') !== [], 30, 'the Dumps job');

        foreach (self::BOTH as $container) {
            $marker = self::rows($container, 'Dumps')[0]['value']['marker'];
            $out = trim(self::exec($container, 'grep -l '.escapeshellarg($marker).' /tmp/queue-work-*.out')[1]);
            $err = trim(self::exec($container, 'grep -l '.escapeshellarg("Dumps fixture stderr {$marker}").' /tmp/queue-work-*.err')[1]);

            $this->assertMatchesRegularExpression('#^/tmp/queue-work-\d+\.out$#', $out, "{$container}: dump() in one program's stdout log");
            $this->assertSame(substr($out, 0, -4).'.err', $err, "{$container}: STDERR in the same program's stderr log");
        }
    }

    public function test_queue_restart_with_jobs_in_flight(): void
    {
        $old = self::startSleepers(8);
        self::both(fn ($c) => self::artisan($c, 'queue:restart'));

        self::waitBoth(fn ($c) => count(self::rows($c, 'Sleeps')) === 2, 30, 'the running jobs to finish');
        self::waitBoth(fn ($c) => self::gone($c, $old[$c]) && self::shapeProblem($c) === null, 60, 'every worker replaced');
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=4 --queue='.self::QUEUE));
        self::waitBoth(fn ($c) => count(self::rows($c, 'Succeeds')) === 4, 30, 'new jobs on the new workers');

        $this->compare('queue:restart', ['Sleeps' => 2, 'Succeeds' => 4, 'failed' => 0]);
    }

    public function test_supervisorctl_stop_waits_for_the_running_job(): void
    {
        $old = self::startSleepers(5, steady: true);
        $took = [];

        foreach (self::BOTH as $container) {
            $started = microtime(true);
            self::supervisorctl($container, "stop 'queue-work:*'");
            $took[$container] = microtime(true) - $started;
        }

        $this->assertCount(2, self::rows(self::STOCK, 'Sleeps'));
        $this->assertCount(2, self::rows(self::PREFORK, 'Sleeps'), 'stop returns after the job finished, as in stock');
        $this->assertLessThan(self::STOPWAIT, $took[self::PREFORK], 'and the program stopped by itself, not by SIGKILL');
        $this->assertTrue(self::gone(self::PREFORK, $old[self::PREFORK]), 'no worker outlives its program');
        self::assertNoStrays(self::PREFORK);

        self::both(fn ($c) => self::supervisorctl($c, "start 'queue-work:*'"));
        $this->compare('supervisorctl stop', ['Sleeps' => 2, 'failed' => 0]);
    }

    public function test_a_stop_past_stopwaitsecs_kills_the_worker_with_its_job(): void
    {
        // supervisord SIGKILLs the program's group after stopwaitsecs. The stub dies with it; the
        // template then SIGKILLs the stub's worker (its own process group) within about a second.
        $old = self::startSleepers(self::STOPWAIT + 15, steady: true);
        self::both(fn ($c) => self::supervisorctl($c, "stop 'queue-work:*'"));

        self::waitBoth(fn ($c) => self::gone($c, $old[$c]), 5, 'the old workers to be killed');
        self::assertNoStrays(self::PREFORK);

        self::both(fn ($c) => self::supervisorctl($c, "start 'queue-work:*'"));
        $this->compare('stop past stopwaitsecs', ['Sleeps.started' => 2, 'Sleeps' => 0, 'failed' => 0]);
    }

    public function test_pause_and_continue_through_supervisorctl(): void
    {
        self::both(fn ($c) => self::supervisorctl($c, "signal USR2 'queue-work:*'"));
        sleep(2);
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=3 --queue='.self::QUEUE));
        sleep(5);

        $this->assertSame([], self::rows(self::STOCK, 'Succeeds'), 'stock takes no job while paused');
        $this->assertSame([], self::rows(self::PREFORK, 'Succeeds'), 'neither does prefork');

        self::both(fn ($c) => self::supervisorctl($c, "signal CONT 'queue-work:*'"));
        self::waitBoth(fn ($c) => count(self::rows($c, 'Succeeds')) === 3, 30, 'the jobs after CONT');

        $this->compare('USR2, CONT', ['Succeeds' => 3, 'failed' => 0]);
    }

    public function test_a_job_past_its_timeout_kills_its_worker_and_the_program_restarts(): void
    {
        $before = self::programsOfBoth();

        // TimesOut: $timeout 2, so the worker's SIGALRM handler fails the job and SIGKILLs the worker.
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch times-out --queue='.self::QUEUE));
        self::waitBoth(fn ($c) => count(self::rows($c, 'TimesOut.failed')) === 1, 40, 'the timed-out job to fail');
        self::waitBoth(fn ($c) => self::restartedOne($c, $before[$c]) && self::shapeProblem($c) === null, 60, 'its program restarted');

        $this->compare('--timeout kill', ['TimesOut.started' => 1, 'TimesOut.failed' => 1, 'TimesOut.finished' => 0, 'failed' => 1]);
        $this->assertSame(['exited: terminated by SIGKILL; not expected'], $this->newExits(self::PREFORK), 'the stub dies of the worker\'s signal');
    }

    public function test_a_worker_that_exits_mid_job_restarts_its_program(): void
    {
        $before = self::programsOfBoth();

        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch crashes --crash=exit --queue='.self::QUEUE));
        self::waitBoth(fn ($c) => self::restartedOne($c, $before[$c]) && self::shapeProblem($c) === null, 60, 'its program restarted');

        $this->compare('exit(255) mid-job', ['Crashes' => 1]);
        $this->assertSame(['exited: exit status 255; not expected'], $this->newExits(self::PREFORK), 'the stub exits with the worker\'s code');
    }

    public function test_a_worker_past_its_memory_limit_stops_after_the_job(): void
    {
        $before = self::programsOfBoth();

        // 160 MB kept alive past the job: above queue:work's default --memory of 128, so the worker stops (12).
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch leaks-memory --leak=160 --queue='.self::QUEUE));
        self::waitBoth(fn ($c) => self::restartedOne($c, $before[$c]) && self::shapeProblem($c) === null, 60, 'its program restarted');

        $this->compare('--memory exceeded', ['LeaksMemory' => 1, 'failed' => 0]);
        $this->assertSame(['exited: exit status 12; not expected'], $this->newExits(self::PREFORK));
    }

    public function test_a_worker_whose_looping_listeners_are_swallowed_still_reports_its_real_exit(): void
    {
        // An app Looping listener that returns a value hides the package's own listener from Laravel's
        // `until`. The worker must still count as booted (wiring time), so its later --memory exit is
        // reported as exit 12, not mistaken for a boot crash and replaced by a stock exec.
        self::both(fn ($c) => self::exec($c, 'touch storage/framework/workbench-swallow-looping'));

        try {
            $old = self::programsOfBoth();
            self::both(fn ($c) => self::artisan($c, 'queue:restart'));
            self::waitBoth(fn ($c) => count(array_intersect_assoc(self::programs($c), $old[$c])) === 0 && self::shapeProblem($c) === null, 60, 'workers with the swallowing listener');
            $this->exitMark = [self::STOCK => count(self::exits(self::STOCK)), self::PREFORK => count(self::exits(self::PREFORK))];
            $before = self::programsOfBoth();

            self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch leaks-memory --leak=160 --queue='.self::QUEUE));
            self::waitBoth(fn ($c) => self::restartedOne($c, $before[$c]), 60, 'the leaking worker\'s program restarted');

            $this->compare('--memory with swallowed Looping', ['LeaksMemory' => 1, 'failed' => 0]);
            $this->assertSame(['exited: exit status 12; not expected'], $this->newExits(self::PREFORK));
        } finally {
            self::both(fn ($c) => self::exec($c, 'rm -f storage/framework/workbench-swallow-looping'));
            self::both(fn ($c) => self::artisan($c, 'queue:restart'));
        }
    }

    public function test_maintenance_mode_holds_the_queue(): void
    {
        self::both(fn ($c) => self::artisan($c, 'down'));
        self::both(fn ($c) => self::artisan($c, 'fixtures:dispatch succeeds --times=2 --queue='.self::QUEUE));
        sleep(6);

        $this->assertSame([], self::rows(self::STOCK, 'Succeeds'));
        $this->assertSame([], self::rows(self::PREFORK, 'Succeeds'));

        self::both(fn ($c) => self::artisan($c, 'up'));
        self::waitBoth(fn ($c) => count(self::rows($c, 'Succeeds')) === 2, 30, 'the jobs after up');
        $this->compare('maintenance mode', ['Succeeds' => 2, 'failed' => 0]);
    }

    public function test_a_killed_stub_takes_its_worker_with_it(): void
    {
        $programs = self::programs(self::PREFORK);
        $name = array_key_first($programs);
        $worker = self::workerOf(self::PREFORK, $programs[$name]);
        self::kill(self::PREFORK, $programs[$name]);

        self::waitUntil(fn () => ! self::alive(self::PREFORK, $worker['pid'], $worker['start']), 5, 'the template to kill the stub\'s worker');
        self::waitUntil(fn () => self::shapeProblem(self::PREFORK) === null, 30, 'supervisord to restart the program');
        $this->assertNotSame($programs[$name], self::programs(self::PREFORK)[$name]);
        self::assertNoStrays(self::PREFORK);
    }

    public function test_workers_keep_serving_without_their_template_and_the_next_spawn_starts_a_new_one(): void
    {
        $template = self::template(self::PREFORK);
        $workers = self::workers(self::PREFORK);
        self::kill(self::PREFORK, $template['pid']);
        sleep(2);

        foreach ($workers as $worker) {
            $this->assertTrue(self::alive(self::PREFORK, $worker['pid'], $worker['start']), 'workers outlive the template');
        }

        self::artisan(self::PREFORK, 'fixtures:dispatch succeeds --times=3 --queue='.self::QUEUE);
        self::waitUntil(fn () => count(self::rows(self::PREFORK, 'Succeeds')) === 3, 30, 'jobs processed without a template');

        // The workers stop; their stubs (no status will come) exit 1 once they're gone; supervisord restarts them.
        self::artisan(self::PREFORK, 'queue:restart');
        self::waitUntil(fn () => self::gone(self::PREFORK, $workers) && self::shapeProblem(self::PREFORK) === null, 60, 'a new template and new workers');
        $this->assertNotSame($template['pid'], self::template(self::PREFORK)['pid']);
        self::assertNoStrays(self::PREFORK);

        // The one deviation: with the template gone, no status reaches the stubs, so they exit 1 where a
        // stock worker's queue:restart exit is 0. supervisord restarts either way (autorestart=true or
        // unexpected).
        $this->assertSame(array_fill(0, count($workers), 'exited: exit status 1; not expected'), $this->newExits(self::PREFORK));
    }

    public function test_retire_moves_new_workers_to_a_fresh_template_while_the_old_one_drains(): void
    {
        $old = self::template(self::PREFORK);
        $workers = self::workers(self::PREFORK);
        $this->assertStringContainsString('retiring, '.count($workers).' workers draining', self::artisan(self::PREFORK, 'queue-prefork:retire'));

        foreach ($workers as $worker) {
            $this->assertTrue(self::alive(self::PREFORK, $worker['pid'], $worker['start']), 'retiring kills nothing');
        }

        self::artisan(self::PREFORK, 'queue:restart');
        self::waitUntil(fn () => self::gone(self::PREFORK, $workers) && self::shapeProblem(self::PREFORK) === null, 60, 'new workers from a new template');
        $this->assertNotSame($old['pid'], self::template(self::PREFORK)['pid']);
        self::waitUntil(fn () => ! self::alive(self::PREFORK, $old['pid'], $old['start']), 10, 'the drained template to exit');
        $this->assertSame(array_fill(0, count($workers), 'exited: exit status 0; expected'), $this->newExits(self::PREFORK), 'the old workers still report to their stubs');
    }

    public function test_without_ffi_an_app_logging_to_stderr_runs_stock_and_marks_its_key(): void
    {
        // Shared-log mode would move a stderr log channel out of the program's output, so the worker
        // declines after booting (LogTargets), its stub runs stock in place, and the key is marked so
        // later stubs go straight to stock. The config is loaded fresh, so LOG_CHANNEL applies.
        $script = <<<'SH'
            out=$(mktemp); err=$(mktemp)
            APP_CONFIG_CACHE=/tmp/qp-none.php LOG_CHANNEL=stderr php -d ffi.enable=0 artisan queue:work redis --queue=qp-none --sleep=1 >"$out" 2>"$err" &
            pid=$!
            sleep 10
            echo "cmd=$(tr '\0' ' ' < /proc/$pid/cmdline)"
            echo "handoff=$(tr '\0' '\n' < /proc/$pid/environ | grep -c '^QUEUE_PREFORK_HANDOFF=0$')"
            echo "marker=$(cat /tmp/qp-1000/*.stock 2>/dev/null | grep -c logs-to-stdio)"
            echo "notice=$(grep -c 'install the PHP FFI extension' "$err")"
            kill -TERM $pid; wait $pid
            rm -f "$out" "$err" /tmp/qp-1000/*.stock
            SH;
        $output = self::exec(self::PREFORK, $script)[1];

        $this->assertMatchesRegularExpression('#^cmd=\S*php -d ffi.enable=0 artisan queue:work redis --queue=qp-none#m', $output, 'still the program\'s own pid, now a stock worker with its own options');
        $this->assertStringContainsString('handoff=1', $output, 'exec\'d by its stub with the handoff off');
        $this->assertStringContainsString('marker=1', $output, 'the key is marked logs-to-stdio');
        $this->assertStringContainsString('notice=1', $output, 'one notice in the program\'s own stderr');
    }
}
