<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\StatusFiles;

/**
 * The template, fork servers and child routine, end to end, with fake entry scripts.
 */
class TemplateMechanicsTest extends TestCase
{
    private ?TemplateHarness $t = null;

    protected function tearDown(): void
    {
        $this->t?->close();
    }

    public function test_template_is_ready_and_neutral(): void
    {
        $this->t = new TemplateHarness;

        $this->assertSame(\Tevkert\QueuePrefork\Runtime::PROTOCOL, $this->t->ready['protocol']);
        $this->assertTrue($this->t->ready['opcache']['enabled'] ?? false, 'opcache must be active in the template');
        $title = trim((string) shell_exec('ps -o command= -p '.$this->t->ready['pid']));
        $this->assertStringContainsString('hp-template', $title);
        $this->assertStringNotContainsString('horizon', $title, 'horizon:purge must never match the template');
    }

    public function test_the_masters_sockets_never_reach_the_template(): void
    {
        // Like R's Redis and database connections: open sockets the template must not inherit.
        $held = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        $this->t = new TemplateHarness;
        $this->assertSame('ready', $this->t->ready['t'], 'masked: the template starts clean');
        $this->assertSame([], $this->t->ready['warnings']);
        $this->t->close();

        // Unmasked, the audit refuses them (Linux only: on macOS the audit only warns, best effort).
        if (PHP_OS_FAMILY === 'Linux') {
            $this->t = new TemplateHarness([], null, false);
            $this->assertSame('unsafe', $this->t->ready['t']);
            $this->assertStringContainsString('socket', implode('; ', $this->t->ready['reasons']));
        }

        fclose($held[0]);
        fclose($held[1]);
    }

    public function test_unit_and_workers_lifecycle(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnUnit('u1', '--workers=2 --worker-exit=7 --exit=3');

        $spawned = $this->t->await('spawned', 10, 'u1');
        $this->assertNotNull($spawned);
        $this->t->channel->send(['t' => 'admit', 'id' => 'u1']);

        $exited = $this->t->await('exited', 20, 'u1');
        $this->assertSame(3, $exited['status'] ?? null, 'F exits with L\'s status');
        $this->assertSame(3, StatusFiles::final($this->t->statusDir, Identity::key($spawned['pid'], $spawned['start'])), 'F wrote the final status');

        [$loop] = $this->t->events('loop-started');
        $this->assertSame($spawned['loop_pid'], $loop['pid']);
        $this->assertSame($spawned['pid'], $loop['ppid'], 'L is F\'s child');
        $this->assertSame($spawned['pid'], $loop['server']);
        $this->assertSame('requester-env', $loop['env_marker'], 'the child gets the requester\'s environment');
        $this->assertFalse($loop['role_env']);
        $this->assertStringStartsWith('php ', $loop['title']);
        $this->assertStringContainsString('horizon:supervisor test:fake redis', $loop['title']);

        $workers = $this->t->events('worker');
        $this->assertCount(2, $workers);

        foreach ($workers as $worker) {
            $this->assertSame($spawned['pid'], $worker['ppid'], 'workers are F\'s children, so horizon:purge spares them');
            $this->assertStringEndsWith('fake-worker.php', $worker['argv'][0], 'argv[0] is the script');
            $this->assertSame('horizon:work', $worker['argv'][1]);
            $this->assertSame($worker['argv'], $worker['server_argv']);
            $this->assertStringContainsString('horizon:work redis --supervisor=test:fake', $worker['title']);
            $this->assertTrue($worker['opcache'], 'children share the template\'s opcache');
            $this->assertTrue($worker['stdin_ok'], 'stdio stays valid in children');
        }

        $this->assertNotSame($workers[0]['mt_rand'], $workers[1]['mt_rand'], 'mt_rand is reseeded per child');
        $envs = array_column($workers, 'env');
        sort($envs);
        $this->assertSame(['from-loop-1', 'from-loop-2'], $envs, 'each worker gets its requester\'s environment');

        $exits = array_filter($this->t->events('from-server'), fn ($e) => $e['m']['t'] === 'exited');
        $this->assertCount(2, $exits);

        foreach ($exits as $exit) {
            $this->assertSame(7, $exit['m']['status']);
            $this->assertTrue($exit['m']['ready']);
        }
    }

    public function test_an_unadmitted_unit_runs_nothing(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnUnit('u2', '--exit=0');
        $spawned = $this->t->await('spawned', 10, 'u2');
        $this->t->channel->send(['t' => 'cancel', 'id' => 'u2']);

        $exited = $this->t->await('exited', 10, 'u2');
        $this->assertSame(75, $exited['status'] ?? null);
        $this->assertSame([], $this->t->events('loop-started'), 'L never ran its entry script');
        $this->assertFalse(Identity::alive($spawned['loop_pid'], $spawned['loop_start']));
    }

    public function test_supervisor_signals_wait_until_the_loop_is_armed(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnUnit('u3', '--armed-after=2 --hold=4 --exit=5');
        $spawned = $this->t->await('spawned', 10, 'u3');
        $this->t->channel->send(['t' => 'admit', 'id' => 'u3']);
        $this->t->waitForEvents('loop-started', 1);

        // Like `horizon:pause-supervisor`: USR2 straight to the recorded supervisor pid (F).
        posix_kill($spawned['pid'], SIGUSR2);

        $exited = $this->t->await('exited', 20, 'u3');
        $this->assertSame(5, $exited['status'] ?? null, 'L survived USR2 sent before its handlers existed');
        $signals = array_column($this->t->events('signal'), 'sig');
        $this->assertContains(SIGUSR2, $signals, 'and received it once armed');
    }

    public function test_the_template_routes_signals_and_kills_both_on_sigkill(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnUnit('u4', '--hold=30');
        $spawned = $this->t->await('spawned', 10, 'u4');
        $this->t->channel->send(['t' => 'admit', 'id' => 'u4']);
        $this->t->waitForEvents('loop-started', 1);
        usleep(300000);

        $this->t->channel->send(['t' => 'signal', 'id' => 'u4', 'sig' => SIGTERM, 'target' => 'one']);
        $this->assertSame(true, $this->t->await('signalled', 5, 'u4')['delivered'] ?? null);
        $exited = $this->t->await('exited', 10, 'u4');
        $this->assertSame(0, $exited['status'], 'TERM relayed to L, which terminated cleanly');
        $this->assertCount(1, $this->t->events('terminating'));
    }

    public function test_the_loop_sees_its_fork_server_die(): void
    {
        $this->t = new TemplateHarness;
        $this->t->spawnUnit('u5', '--hold=30');
        $spawned = $this->t->await('spawned', 10, 'u5');
        $this->t->channel->send(['t' => 'admit', 'id' => 'u5']);
        $this->t->waitForEvents('loop-started', 1);

        posix_kill($spawned['pid'], SIGKILL);

        $exited = $this->t->await('exited', 10, 'u5');
        $this->assertSame(137, $exited['status']);
        $this->assertCount(1, $this->t->waitForEvents('server-lost', 1), 'L noticed through EOF');
    }

    public function test_a_loop_without_hello_is_ended_with_71(): void
    {
        $this->t = new TemplateHarness(['hello_timeout' => 2]);
        $this->t->spawnUnit('u6', '--no-hello --armed-after=30');
        $spawned = $this->t->await('spawned', 10, 'u6');
        $this->t->channel->send(['t' => 'admit', 'id' => 'u6']);

        $exited = $this->t->await('exited', 20, 'u6');
        $this->assertSame(71, $exited['status']);
        $this->assertSame(71, StatusFiles::final($this->t->statusDir, Identity::key($spawned['pid'], $spawned['start'])));
    }

    public function test_everything_stops_when_the_master_is_gone(): void
    {
        $master = proc_open([PHP_BINARY, '-r', 'sleep(60);'], [], $pipes);
        $masterPid = proc_get_status($master)['pid'];
        usleep(200000);
        $this->t = new TemplateHarness([], $masterPid);
        $this->t->spawnUnit('u7', '--hold=30');
        $spawned = $this->t->await('spawned', 10, 'u7');
        $this->t->channel->send(['t' => 'admit', 'id' => 'u7']);
        $this->t->waitForEvents('loop-started', 1);

        posix_kill($masterPid, SIGKILL);
        proc_close($master);

        $this->assertCount(1, $this->t->waitForEvents('terminating', 1, 15), 'F relayed TERM to L once R was gone');
        $deadline = microtime(true) + 10;

        while (microtime(true) < $deadline && proc_get_status($this->t->process)['running']) {
            usleep(100000);
        }

        $this->assertFalse(proc_get_status($this->t->process)['running'], 'the template exited too');
    }
}
