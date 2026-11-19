<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use PHPUnit\Framework\TestCase;

/**
 * A master that runs its supervisors in its own process asks the template for each worker directly
 * (`spawn` with `role: worker`): no fork server and no supervisor loop between them.
 */
class TemplateWorkersTest extends TestCase
{
    private ?TemplateHarness $t = null;

    protected function setUp(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl');
        }
    }

    protected function tearDown(): void
    {
        $this->t?->close();
    }

    public function test_the_template_forks_a_worker_for_its_master_and_reports_its_lifecycle(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnWorker('w1', '--exit=5');

        $spawned = $this->t->await('spawned', 10, 'w1');
        $this->assertNotNull($spawned);
        $this->t->channel->send(['t' => 'admit', 'id' => 'w1', 'paused' => false]);

        $this->assertNotNull($this->t->await('ready', 10, 'w1'), 'the worker\'s first loop reaches the master');
        $exited = $this->t->await('exited', 10, 'w1');
        $this->assertSame(5, $exited['status'] ?? null);
        $this->assertTrue($exited['ready']);
        $this->assertSame($spawned['pid'], $exited['pid']);

        [$worker] = $this->t->events('worker');
        $this->assertSame($spawned['pid'], $worker['pid']);
        $this->assertSame($this->t->ready['pid'], $worker['ppid'], 'the template itself is the worker\'s parent');
        $this->assertSame('horizon:work', $worker['argv'][1]);
        $this->assertSame('from-master', $worker['env'], 'the worker gets the requester\'s environment');
        $this->assertTrue($worker['opcache'], 'the worker shares the template\'s opcache');
        $this->assertTrue($worker['stdin_ok']);
    }

    public function test_the_template_relays_a_signal_to_its_worker(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnWorker('w2', '--hold=30');
        $this->assertNotNull($this->t->await('spawned', 10, 'w2'));
        $this->t->channel->send(['t' => 'admit', 'id' => 'w2', 'paused' => false]);
        $this->assertNotNull($this->t->await('ready', 10, 'w2'));

        $this->t->channel->send(['t' => 'signal', 'id' => 'w2', 'sig' => SIGTERM]);

        $this->assertTrue($this->t->await('signalled', 10, 'w2')['delivered'] ?? false);
        $this->assertSame(128 + SIGTERM, $this->t->await('exited', 10, 'w2')['status'] ?? null);
    }

    public function test_the_template_forks_only_horizon_workers_as_workers(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnWorker('w3', '--exit=0', 'horizon:supervisor');

        $failed = $this->t->await('spawn_failed', 10, 'w3');
        $this->assertNotNull($failed);
        $this->assertSame([], $this->t->events('worker'), 'nothing ran');
    }

    public function test_the_template_stops_its_workers_when_the_master_is_gone(): void
    {
        $master = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [], $pipes);
        $masterPid = proc_get_status($master)['pid'];
        usleep(200000);
        $this->t = new TemplateHarness([], $masterPid);
        $this->t->spawnWorker('w4', '--hold=30');
        $spawned = $this->t->await('spawned', 10, 'w4');
        $this->t->channel->send(['t' => 'admit', 'id' => 'w4', 'paused' => false]);
        $this->assertNotNull($this->t->await('ready', 10, 'w4'));

        posix_kill($masterPid, SIGKILL);
        proc_close($master);

        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline && self::running($spawned['pid'])) {
            usleep(100000);
        }

        $this->assertFalse(self::running($spawned['pid']), 'the worker got TERM instead of outliving the template');
    }

    /** Running, not a zombie: an orphan waits to be reaped by whatever PID 1 is. */
    private static function running(int $pid): bool
    {
        $stat = @file_get_contents("/proc/{$pid}/stat");

        return $stat !== false && preg_match('/\) ([A-Z]) /', $stat, $m) === 1 && $m[1] !== 'Z';
    }
}
