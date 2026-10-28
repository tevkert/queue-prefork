<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use Tevkert\QueuePrefork\Support\Identity;

/** The per-worker fork's core paths: forking, statuses, no stub, decline, breaker, retire, shared-log. */
class QueueTemplateTest extends QueueCase
{
    public function test_a_worker_is_forked_from_the_template_and_the_stub_mirrors_it(): void
    {
        $stub = $this->stub('one', ['FIXTURE_MARK' => 'hello', 'FIXTURE_DRAIN' => '0.4']);
        $worker = $this->workerPid('one');

        $this->assertNotSame($stub, $worker, 'the worker is a fork, not the started process');
        $this->assertTrue($this->eventually(fn () => str_contains($this->commandOf($stub), 'stub-')), 'the started process became the stub (same pid)');
        $this->assertStringContainsString('worker forked pid='.$worker, $this->out('one'));
        $this->assertStringContainsString('"queue:work","--queue={high},default"', $this->out('one'), 'argv is the stock one');
        $this->assertStringContainsString('env=hello', $this->out('one'), 'the stub\'s environment');
        $this->assertStringContainsString('STDOUT constant line', $this->out('one'), 'the STDOUT constant reaches the stub\'s stdout');
        $this->assertStringContainsString('stderr line from forked', $this->err('one'), 'stderr reaches the stub\'s stderr');

        posix_kill($stub, SIGTERM);
        $this->assertSame(['exit' => 7], $this->waitFor($stub, 5.0));
        $this->assertStringContainsString('TERM received', $this->out('one'));
    }

    public function test_a_second_stub_reuses_the_template(): void
    {
        $this->stub('a');
        $template = $this->templatePid();
        $this->stub('b');

        $this->assertSame($template, $this->templatePid());
        $this->assertCount(1, glob($this->runtime.'/*.pid'), 'one template for the key');
        $this->assertSame((int) Identity::ppid($this->workerPid('b')), $template);
    }

    public function test_exit_codes_and_signal_deaths_reach_the_process_manager(): void
    {
        $stub = $this->stub('ok', ['FIXTURE_LIFETIME' => '1', 'FIXTURE_EXIT' => '12']);
        $this->assertSame(['exit' => 12], $this->waitFor($stub, 6.0), 'like --memory: the worker exits 12');

        $stub = $this->stub('killed');
        posix_kill($this->workerPid('killed'), SIGKILL);
        $this->assertSame(['signal' => SIGKILL], $this->waitFor($stub, 5.0), 'a worker killed by --timeout shows as SIGKILL');
    }

    public function test_without_a_free_low_descriptor_the_process_boots_stock_and_no_worker_runs(): void
    {
        $this->stub('warm'); // a ready template: the crowded request is forked and parked at once
        $stub = $this->stub('crowded', ['FIXTURE_LIFETIME' => '0.5'], waitForWorker: false, crowded: true);

        $this->assertSame(['exit' => 0], $this->waitFor($stub, 8.0));
        $this->assertStringContainsString('worker stock pid='.$stub, $this->out('crowded'), 'the stub script needs a descriptor below 10: stock in place');
        $this->assertStringContainsString('stub script could not run', $this->err('crowded'));
        $this->assertSame(0, $this->workerPid('crowded'), 'its parked worker was never released');
    }

    public function test_when_the_stub_is_killed_the_template_kills_its_worker(): void
    {
        $stub = $this->stub('victim');
        $worker = $this->workerPid('victim');
        $start = Identity::startTime($worker);

        posix_kill($stub, SIGKILL);
        $this->waitFor($stub, 2.0);

        $this->assertTrue($this->eventually(fn () => ! Identity::alive($worker, $start), 4.0), 'the worker died with its stub');
        $this->assertTrue($this->eventually(fn () => glob($this->runtime.'/req-*') === [], 4.0), 'and its request files were reclaimed');
    }

    public function test_a_declined_worker_turns_the_stub_into_a_stock_worker_in_place(): void
    {
        $stub = $this->stub('declined', ['FIXTURE_DECLINE' => '1', 'FIXTURE_LIFETIME' => '1', 'FIXTURE_EXIT' => '5']);

        $this->assertSame(['exit' => 5], $this->waitFor($stub, 8.0));
        $this->assertStringContainsString('worker stock pid='.$stub, $this->out('declined'), 'the stock worker runs in the stub\'s own pid');
    }

    public function test_the_handoff_switch_boots_stock(): void
    {
        $stub = $this->stub('off', ['QUEUE_PREFORK' => '0', 'FIXTURE_LIFETIME' => '0.5'], waitForWorker: false);

        $this->assertSame(['exit' => 0], $this->waitFor($stub, 5.0));
        $this->assertStringContainsString('worker stock pid='.$stub, $this->out('off'));
        $this->assertSame([], glob($this->runtime.'/*.pid') ?: [], 'no template was started');
    }

    public function test_once_and_stop_when_empty_boot_stock_without_a_template(): void
    {
        foreach (['--once', '--stop-when-empty'] as $flag) {
            $stub = $this->stub(ltrim($flag, '-'), ['FIXTURE_LIFETIME' => '0.3'], waitForWorker: false, args: [$flag]);

            $this->assertSame(['exit' => 0], $this->waitFor($stub, 5.0));
            $this->assertStringContainsString('worker stock pid='.$stub, $this->out(ltrim($flag, '-')));
        }

        $this->assertSame([], glob($this->runtime.'/*') ?: [], 'no template, no runtime files');
    }

    public function test_a_boot_crash_runs_stock_in_place_and_crash_loops_trip_the_breaker(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $stub = $this->stub("crash{$i}", ['FIXTURE_CRASH' => '1', 'FIXTURE_LIFETIME' => '0.3'], waitForWorker: false);

            $this->assertSame(['exit' => 0], $this->waitFor($stub, 8.0), 'the process manager sees no failed start');
            $this->assertStringContainsString('worker stock pid='.$stub, $this->out("crash{$i}"), 'the stub ran stock in place');
        }

        $this->assertTrue($this->eventually(fn () => glob($this->runtime.'/*.stock') !== []), 'the breaker wrote the stock marker');

        $stub = $this->stub('after', ['FIXTURE_CRASH' => '1', 'FIXTURE_LIFETIME' => '0.3'], waitForWorker: false);
        $this->assertSame(['exit' => 0], $this->waitFor($stub, 5.0));
        $this->assertStringContainsString('worker stock pid='.$stub, $this->out('after'), 'straight to stock: no fork to crash');
    }

    public function test_when_the_template_dies_the_stub_waits_for_its_worker(): void
    {
        $stub = $this->stub('orphan');
        $worker = $this->workerPid('orphan');
        posix_kill($this->templatePid(), SIGKILL);
        usleep(1500000);

        $this->assertSame(0, pcntl_waitpid($stub, $status, WNOHANG), 'the stub never exits while its worker lives');
        posix_kill($worker, SIGKILL);
        $this->assertSame(['exit' => 1], $this->waitFor($stub, 4.0));
    }

    public function test_after_a_retire_new_stubs_get_a_new_template_while_the_old_one_drains(): void
    {
        $first = $this->stub('old');
        $old = $this->templatePid();
        $this->request(['t' => 'retire']);

        $this->stub('new');
        $new = $this->templatePid();

        $this->assertNotSame($old, $new);
        $this->assertTrue(Identity::alive($old, Identity::startTime($old)), 'the old template keeps draining its worker');
        $this->assertSame((int) Identity::ppid($this->workerPid('new')), $new);

        posix_kill($first, SIGTERM);
        $this->assertSame(['exit' => 7], $this->waitFor($first, 5.0));
        $this->assertTrue($this->eventually(fn () => ! Identity::alive($old, null), 4.0), 'the drained old template exits');
    }

    public function test_a_retiring_template_unpublishes_only_under_the_launch_lock(): void
    {
        $this->stub('one');
        $socket = (glob($this->runtime.'/*.sock') ?: [''])[0];
        $lock = fopen(substr($socket, 0, -5).'.lock', 'c');
        flock($lock, LOCK_EX); // as a launcher replacing a dead template's files would hold it

        $this->assertSame('retiring', $this->request(['t' => 'retire'])['t'] ?? null);
        sleep(2);
        $this->assertFileExists($socket, 'nothing is unlinked without the lock');

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertTrue($this->eventually(fn () => ! file_exists($socket) && glob($this->runtime.'/*.pid') === [], 3.0), 'unlinked once the lock is free');
    }

    public function test_shared_log_mode_without_ffi(): void
    {
        $this->linuxOnly();
        $stub = $this->stub('shared', ['FIXTURE_DRAIN' => '0.3'], php: ['-d', 'ffi.enable=0']);

        $this->assertStringContainsString('worker forked', $this->sharedLog(), 'worker output goes to the shared log');
        $this->assertStringNotContainsString('worker forked', $this->out('shared'));
        $this->assertStringContainsString('[queue-prefork] worker output goes to', $this->err('shared'), 'one notice in the program\'s own log');
        $this->assertSame(1, substr_count($this->err('shared'), '[queue-prefork]'));

        posix_kill($stub, SIGTERM);
        $this->assertSame(['exit' => 7], $this->waitFor($stub, 5.0), 'the status arrives on the inherited PHP stream');
    }

    public function test_shared_log_mode_refuses_a_forged_pid(): void
    {
        $this->linuxOnly();
        $this->stub('owner', php: ['-d', 'ffi.enable=0']);
        $socket = (glob($this->runtime.'/*.sock') ?: [null])[0];
        $victim = $this->workerPid('owner');

        $stream = stream_socket_client('unix://'.$socket, $errno, $error, 2.0);
        fwrite($stream, json_encode(['t' => 'spawn', 'id' => 'ab', 'pid' => $victim, 'protocol' => \Tevkert\QueuePrefork\Runtime::PROTOCOL])."\n");
        $prove = json_decode((string) fgets($stream), true);
        $this->assertSame('prove', $prove['t']);
        fwrite($stream, "{\"t\":\"proved\"}\n"); // without opening the token: only the claimed process could
        $reply = json_decode((string) fgets($stream), true);

        $this->assertSame('refused', $reply['t']);
        $this->assertSame('proof of control failed', $reply['reason']);
    }

    public function test_the_shared_log_rotates_while_the_worker_keeps_writing(): void
    {
        $this->linuxOnly();
        $this->stub('chatty', ['FIXTURE_SPAM' => '200', 'QUEUE_PREFORK_LOG_MAX_MB' => '0.01', 'QUEUE_PREFORK_LOG_FILES' => '2'], php: ['-d', 'ffi.enable=0']);
        $log = (glob($this->runtime.'/queue-prefork-*.log') ?: [''])[0];

        $this->assertTrue($this->eventually(fn () => is_file($log.'.1'), 25.0), 'rotated to .1');
        clearstatcache();
        $size = filesize($log);
        usleep(600000);
        clearstatcache();
        $this->assertGreaterThan($size, filesize($log), 'the worker keeps writing to the rotated log');
        $this->assertFileExists($log.'.owners', 'the template holds a lease on its log');
    }
}
