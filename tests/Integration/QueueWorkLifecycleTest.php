<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Failures in the middle of a job under `queue:work`, stock against prefork: what happens to the
 * running job (finished, failed, run twice), how supervisord sees the program end, and how the
 * programs recover. The Horizon counterpart is LifecycleParityTest.
 *
 * A killed job stays reserved until the queue's retry_after (180 s, docker/compose.yml) and is then
 * failed by another worker (its tries is 1), so the kill tests take minutes.
 */
class QueueWorkLifecycleTest extends QueueWorkCase
{
    /** retry_after plus a margin for a worker to pick the expired job up. */
    private const RETRY_WAIT = 240;

    public function test_killing_a_worker_or_its_program_mid_job_fails_the_job_as_in_stock(): void
    {
        self::startSleepers(120, steady: true);

        foreach (self::BOTH as $container) {
            $busy = array_map('intval', array_column(self::rows($container, 'Sleeps.started'), 'pid'));

            if ($container === self::STOCK) {
                array_map(fn ($pid) => self::kill($container, $pid), $busy); // the worker is the program
            } else {
                self::kill($container, $busy[0]); // the forked worker itself
                self::kill($container, self::stubOf($container, $busy[1])); // the program's own pid: its stub
            }
        }

        self::waitBoth(fn ($c) => self::shapeProblem($c) === null, 60, 'the two programs restarted');
        self::waitBoth(fn ($c) => self::failed($c) === 2, self::RETRY_WAIT, 'both killed jobs to fail after retry_after');

        $this->compare('SIGKILL mid-job (worker; program)', ['Sleeps.started' => 2, 'Sleeps' => 0, 'failed' => 2]);
        $this->assertSame(['exited: terminated by SIGKILL; not expected', 'exited: terminated by SIGKILL; not expected'], $this->newExits(self::PREFORK));
        self::assertNoStrays(self::PREFORK);
    }

    public function test_a_template_killed_mid_job_lets_the_job_finish(): void
    {
        $workers = self::startSleepers(6, steady: true)[self::PREFORK];
        self::kill(self::PREFORK, self::template(self::PREFORK)['pid']);

        self::waitUntil(fn () => count(self::rows(self::PREFORK, 'Sleeps')) === 2, 30, 'the running jobs to finish without their template');
        $this->assertSame(0, self::failed(self::PREFORK));

        // Then the workers are replaced as usual, from a new template.
        self::artisan(self::PREFORK, 'queue:restart');
        self::waitUntil(fn () => self::gone(self::PREFORK, $workers) && self::shapeProblem(self::PREFORK) === null, 60, 'a new template and new workers');
        self::assertNoStrays(self::PREFORK);
    }

    public function test_a_supervisord_restart_with_jobs_running(): void
    {
        self::startSleepers(5, steady: true);
        self::both(fn ($c) => self::supervisorctl($c, 'reload'));

        self::waitBoth(fn ($c) => count(self::rows($c, 'Sleeps')) === 2, 30, 'the running jobs to finish');
        self::waitBoth(fn ($c) => self::shapeProblem($c) === null, 90, 'every program back');

        $this->compare('supervisord reload', ['Sleeps.started' => 2, 'Sleeps' => 2, 'failed' => 0]);
        self::assertNoStrays(self::PREFORK);
    }

    /** The program (stub) pid whose worker is $worker. */
    private static function stubOf(string $container, int $worker): int
    {
        foreach (self::programs($container) as $pid) {
            $stub = self::process($container, $pid);

            if ($stub !== null && self::isStub($stub) && self::stubWorkerPid($stub) === $worker) {
                return $pid;
            }
        }

        self::fail("no stub for worker {$worker}");
    }
}
