<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Native;

/** Release gates of the per-worker fork that a fixture app can show. */
class QueueGatesTest extends QueueCase
{
    public function test_a_deploy_herd_launches_one_template_and_every_worker_forks_from_it(): void
    {
        $names = array_map(fn ($n) => "h{$n}", range(0, 39)); // above PHP's default listen backlog of 32

        foreach ($names as $name) {
            $this->stub($name, waitForWorker: false);
        }

        foreach ($names as $name) {
            $this->assertTrue($this->eventually(fn () => $this->workerPid($name) > 0, 20.0), "{$name} forked: ".$this->err($name));
        }

        $template = $this->templatePid();

        foreach ($names as $name) {
            $this->assertSame($template, (int) Identity::ppid($this->workerPid($name)));
            $this->others[$this->workerPid($name)] = true;
        }
    }

    public function test_a_warm_up_boot_that_never_leaves_is_killed_at_the_deadline(): void
    {
        // A warm-up that can't reach its exit (as in maintenance mode on a Laravel without WorkerStarting)
        // would otherwise stay as a worker nobody supervises.
        $this->stub('hung', ['FIXTURE_WARMUP_HANG' => '1'], waitForWorker: false);
        $this->assertTrue($this->eventually(fn () => preg_match('/warming up with child (\d+)/', $this->templateLog()) === 1, 6.0), 'a warm-up child: '.$this->templateLog());
        preg_match('/warming up with child (\d+)/', $this->templateLog(), $m);
        $warmup = (int) $m[1];

        $this->assertTrue($this->eventually(fn () => $this->workerPid('hung') > 0, 12.0), 'the stub\'s worker forks after the warm-up wait');
        $this->assertTrue($this->eventually(fn () => ! Identity::alive($warmup, null), 3.0), 'and the warm-up child is gone');
        $this->assertStringContainsString("killed the warm-up child {$warmup}", $this->templateLog());
        $this->others[$this->workerPid('hung')] = true;
    }

    public function test_a_forked_worker_has_a_stock_workers_ignored_signals(): void
    {
        $this->linuxOnly('reads SigIgn from /proc/<pid>/status');
        $ignored = fn (int $pid) => preg_match('/^SigIgn:\s+(\S+)/m', (string) @file_get_contents("/proc/{$pid}/status"), $m) ? $m[1] : null;

        $stock = $this->stub('stock', ['QUEUE_PREFORK' => '0'], waitForWorker: false);
        $this->assertTrue($this->eventually(fn () => str_contains($this->out('stock'), 'worker stock pid='.$stock), 5.0));
        $this->stub('forked');

        // Signals 32 and 33 are glibc's own: proc_open, which launches the template, leaves them ignored,
        // pcntl can't touch them, and glibc installs its handlers whenever it needs them.
        $catchable = fn (?string $mask) => $mask === null ? null : substr($mask, 0, 7).dechex(hexdec($mask[7]) & 0xE).dechex(hexdec($mask[8]) & 0x7).substr($mask, 9); // bits 32 and 31

        $this->assertSame($catchable($ignored($stock)), $catchable($ignored($this->workerPid('forked'))), 'the same SigIgn as stock (SIGPIPE ignored, nothing more)');
    }

    public function test_signals_the_program_ignores_stay_ignored_in_the_stub_and_its_worker(): void
    {
        $stub = $this->stub('nohup', shell: "trap '' HUP; "); // as nohup leaves it; PHP hides it from /proc
        $worker = $this->workerPid('nohup');

        posix_kill($stub, SIGHUP); // a hangup: the stub forwards it, and the worker ignores it, as a stock worker does
        usleep(500000);

        $this->assertSame(0, pcntl_waitpid($stub, $status, WNOHANG), 'the stub runs on');
        $this->assertTrue(Identity::alive($worker, null), 'the worker runs on');
    }

    public function test_a_handler_the_worker_installs_overrides_an_inherited_ignore(): void
    {
        $stub = $this->stub('term-ignored', shell: "trap '' TERM; "); // the worker installs its TERM handler as it boots
        $this->workerPid('term-ignored');

        posix_kill($stub, SIGTERM); // so a stop reaches it, as it reaches a stock worker

        $this->assertSame(['exit' => 7], $this->waitFor($stub, 5.0), 'the worker\'s TERM handler ran: '.$this->out('term-ignored'));
        $this->assertStringContainsString('TERM received', $this->out('term-ignored'));
    }

    public function test_a_php_installed_ignore_doesnt_keep_a_stop_from_the_workers_handler(): void
    {
        $prepend = dirname(self::ARTISAN).'/ignore-term.php';
        $stub = $this->stub('php-ignored', php: ['-d', "auto_prepend_file={$prepend}"]);
        $this->workerPid('php-ignored');

        posix_kill($stub, SIGTERM); // the stub can still trap it, and the worker's own handler gets it

        $this->assertSame(['exit' => 7], $this->waitFor($stub, 5.0), $this->out('php-ignored'));
        $this->assertStringContainsString('TERM received', $this->out('php-ignored'));
    }

    public function test_set_time_limit_ends_a_forked_worker_as_it_ends_a_stock_one(): void
    {
        $stock = $this->stub('stock', ['QUEUE_PREFORK' => '0', 'FIXTURE_TIME_LIMIT' => '1'], waitForWorker: false);
        $forked = $this->stub('forked', ['FIXTURE_TIME_LIMIT' => '1']);

        $this->assertSame(['exit' => 255], $this->waitFor($stock, 8.0), 'stock: PHP\'s timeout');
        $this->assertSame(['exit' => 255], $this->waitFor($forked, 8.0), 'forked: the same, not a SIGPROF death');
        $this->assertStringContainsString('Maximum execution time', $this->out('forked').$this->err('forked'));
    }

    public function test_a_startup_max_execution_time_applies_to_forked_workers(): void
    {
        // A fork doesn't inherit the interval timer: the template re-arms it in each worker (and keeps
        // none for itself). On macOS a `-d` option can't be replayed, so these workers run stock.
        $stock = $this->stub('stock', ['QUEUE_PREFORK' => '0', 'FIXTURE_SPIN' => '1'], waitForWorker: false, php: ['-d', 'max_execution_time=2']);
        $forked = $this->stub('forked', ['FIXTURE_SPIN' => '1'], waitForWorker: PHP_OS_FAMILY === 'Linux', php: ['-d', 'max_execution_time=2']);

        $this->assertSame(['exit' => 255], $this->waitFor($stock, 10.0));
        $this->assertSame(['exit' => 255], $this->waitFor($forked, 10.0));
        $this->assertStringContainsString('Maximum execution time of 2 seconds', $this->out('forked').$this->err('forked'));

        if (PHP_OS_FAMILY === 'Linux') {
            usleep(2500000);
            $this->assertTrue(Identity::alive($this->templatePid(), null), 'the template itself runs on (its timer is off)');
        }
    }

    public function test_a_write_to_a_closed_pipe_is_an_error_not_a_death(): void
    {
        $stub = $this->stub('piped', ['FIXTURE_PIPE' => '1', 'FIXTURE_LIFETIME' => '1']);

        $this->assertSame(['exit' => 0], $this->waitFor($stub, 6.0));
        $this->assertStringContainsString('pipe write survived', $this->out('piped'));
    }

    public function test_a_template_out_of_descriptors_sends_stubs_to_stock(): void
    {
        // The first stub launches the template with its own soft limit: 28 files leave room for a couple
        // of workers only, so the rest must be turned away at once rather than spin or stall.
        $names = array_map(fn ($n) => "f{$n}", range(0, 11));

        foreach ($names as $name) {
            $this->stub($name, ['FIXTURE_LIFETIME' => '2'], waitForWorker: false, shell: 'ulimit -n 28; ');
        }

        foreach ($names as $name) {
            $this->assertTrue($this->eventually(fn () => preg_match('/^worker (forked|stock) pid=/m', $this->out($name)) === 1, 12.0), "{$name} runs: ".$this->err($name));
        }

        $outputs = implode("\n", array_map(fn ($name) => $this->out($name), $names));
        $this->assertStringContainsString('worker forked', $outputs, 'some forked');
        $this->assertStringContainsString('worker stock', $outputs, 'and the rest stock');
        $this->assertStringContainsString('out of descriptors', implode("\n", array_map(fn ($name) => $this->err($name), $names)));
    }

    public function test_statuses_arrive_long_after_admission(): void
    {
        // Leftover handshake timeouts or a non-blocking flag on the connection would turn these into EOF (exit 1).
        $normal = $this->stub('normal', ['FIXTURE_LIFETIME' => '11', 'FIXTURE_EXIT' => '9']);
        $shared = PHP_OS_FAMILY === 'Linux' ? $this->stub('shared', ['FIXTURE_LIFETIME' => '11', 'FIXTURE_EXIT' => '9'], php: ['-d', 'ffi.enable=0']) : null;

        $this->assertSame(['exit' => 9], $this->waitFor($normal, 16.0));

        if ($shared !== null) {
            $this->assertSame(['exit' => 9], $this->waitFor($shared, 4.0), 'shared-log mode');
        }
    }

    public function test_a_worker_runs_in_its_programs_working_directory(): void
    {
        $this->linuxOnly('reads the worker\'s cwd from /proc');
        $this->stub('rooted', cwd: '/');

        $this->assertSame('/', readlink('/proc/'.$this->workerPid('rooted').'/cwd'), 'launched from / with an absolute artisan, as with no directory=');
    }

    public function test_when_its_stub_is_killed_the_workers_whole_group_goes(): void
    {
        $stub = $this->stub('grouped', ['FIXTURE_BACKGROUND' => '1']);
        $background = $this->backgroundPid('grouped');
        $start = Identity::startTime($background);

        posix_kill($stub, SIGKILL);
        $this->waitFor($stub, 2.0);

        $this->assertTrue($this->eventually(fn () => ! Identity::alive($background, $start), 4.0), 'QUEUE_PREFORK_KILL_GROUP=1: the job\'s background process too, as killasgroup does');
    }

    public function test_without_kill_group_only_the_worker_goes(): void
    {
        $stub = $this->stub('alone', ['FIXTURE_BACKGROUND' => '1', 'QUEUE_PREFORK_KILL_GROUP' => '0']);
        $worker = $this->workerPid('alone');
        $background = $this->backgroundPid('alone');
        $start = Identity::startTime($background);

        posix_kill($stub, SIGKILL);
        $this->waitFor($stub, 2.0);

        $this->assertTrue($this->eventually(fn () => ! Identity::alive($worker, null), 4.0), 'the worker dies');
        usleep(500000);
        $this->assertTrue(Identity::alive($background, $start), 'its background process stays, as with killasgroup=false');
    }

    public function test_a_jobs_background_process_outlives_a_normal_exit(): void
    {
        $stub = $this->stub('leaves', ['FIXTURE_BACKGROUND' => '1', 'FIXTURE_LIFETIME' => '1']);
        $background = $this->backgroundPid('leaves');
        $start = Identity::startTime($background);

        $this->assertSame(['exit' => 0], $this->waitFor($stub, 6.0));
        usleep(1500000);
        $this->assertTrue(Identity::alive($background, $start), 'the kill is disarmed once the worker is reaped, as under stock');
    }

    public function test_term_during_boot_never_trips_the_breaker(): void
    {
        $this->stub('warm'); // a fresh template's first request boots a throwaway warm-up child first

        for ($i = 0; $i < 6; $i++) {
            $stub = $this->stub("boot{$i}", ['FIXTURE_BOOT_SLEEP' => '3'], waitForWorker: false);
            $this->assertTrue($this->eventually(fn () => str_contains($this->out("boot{$i}"), 'booting forked'), 8.0), "boot{$i} forked");
            posix_kill($stub, SIGTERM);
            $this->assertSame(['signal' => SIGTERM], $this->waitFor($stub, 5.0), 'the booting worker dies of the TERM, and so does its stub');
        }

        $this->assertSame([], glob($this->runtime.'/*.stock') ?: [], 'stops during boot are not crashes');
    }

    public function test_a_stalled_template_sends_new_stubs_to_stock_in_seconds(): void
    {
        $this->stub('first');
        $template = $this->templatePid();
        posix_kill($template, SIGSTOP);

        try {
            $started = microtime(true);
            $stub = $this->stub('second', ['FIXTURE_LIFETIME' => '0.2'], waitForWorker: false);

            $this->assertSame(['exit' => 0], $this->waitFor($stub, 12.0));
            $this->assertStringContainsString('worker stock pid='.$stub, $this->out('second'));
            $this->assertLessThan(10.0, microtime(true) - $started);
        } finally {
            posix_kill($template, SIGCONT);
        }
    }

    public function test_a_client_that_stalls_blocks_nobody_and_is_dropped(): void
    {
        $this->stub('first');
        $socket = (glob($this->runtime.'/*.sock') ?: [null])[0];
        $stalled = stream_socket_client('unix://'.$socket, $errno, $error, 2.0);
        fwrite($stalled, '{"t":"spa'); // and nothing more

        $started = microtime(true);
        $this->stub('second');
        $this->assertLessThan(5.0, microtime(true) - $started, 'the next stub forks at once');

        stream_set_timeout($stalled, 10);
        $this->assertSame('', (string) fread($stalled, 100), 'the stalled client is dropped at its read deadline');
        $this->assertTrue(feof($stalled));
    }

    public function test_each_stub_runs_its_own_prepend_on_one_template(): void
    {
        foreach (['a', 'b'] as $name) {
            file_put_contents("{$this->dir}/prepend-{$name}.php", "<?php echo 'prepend {$name} pid='.getmypid().\"\\n\";");
            $this->stub("p{$name}", php: ['-d', "auto_prepend_file={$this->dir}/prepend-{$name}.php"]);
        }

        $this->templatePid(); // exactly one: auto_prepend_file is not part of the key
        $this->assertStringContainsString('prepend a pid='.$this->workerPid('pa'), $this->out('pa'));
        $this->assertStringContainsString('prepend b pid='.$this->workerPid('pb'), $this->out('pb'));
    }

    public function test_startup_ini_picks_the_template_and_runtime_ini_set_does_not(): void
    {
        file_put_contents("{$this->dir}/sets.php", "<?php ini_set('memory_limit', '700M');");

        $this->stub('plain');
        $this->stub('runtime', php: ['-d', "auto_prepend_file={$this->dir}/sets.php"]);
        $this->assertCount(1, glob($this->runtime.'/*.pid'), 'an ini_set at runtime shares the template');

        if (PHP_OS_FAMILY !== 'Linux') {
            // No /proc/self/cmdline to replay `-d` options from: the new template's ini check fails, safely.
            $stub = $this->stub('startup', ['FIXTURE_LIFETIME' => '0.3'], waitForWorker: false, php: ['-d', 'memory_limit=300M']);
            $this->assertSame(['exit' => 0], $this->waitFor($stub, 10.0));
            $this->assertStringContainsString('worker stock pid='.$stub, $this->out('startup'));

            return;
        }

        $this->stub('startup', php: ['-d', 'memory_limit=300M']);
        $this->assertCount(2, glob($this->runtime.'/*.pid'), 'a different startup value gets its own');
    }

    public function test_a_client_that_is_not_a_queue_worker_of_the_release_is_refused(): void
    {
        if (! Native::available()) {
            $this->markTestSkipped('needs FFI: '.Native::reason());
        }

        $this->stub('real');
        $native = Native::get();
        $fd = $native->connect((glob($this->runtime.'/*.sock') ?: [''])[0], 3.0);
        $native->sendWithFds($fd, json_encode(['t' => 'spawn', 'id' => bin2hex(random_bytes(8)), 'pid' => getmypid(), 'protocol' => Runtime::PROTOCOL])."\n", [0, 1, 2]);
        $reply = $native->receive($fd, 4096, 0);
        $native->close($fd);

        $reply = json_decode(trim((string) ($reply['data'] ?? '')), true);
        $this->assertSame('refused', $reply['t'] ?? null);
        // Linux reads the peer's real command line (phpunit's); macOS has only the message, which has none.
        $this->assertStringContainsString('Unexpected command line', (string) ($reply['reason'] ?? ''));
    }

    public function test_the_child_gets_its_context_from_the_kernel_not_from_the_message(): void
    {
        $this->linuxOnly('macOS has no /proc: there the kernel-verified peer\'s own message is the context');
        $this->stub('real'); // the template

        // forge.php runs inside a real queue:work of this release and sends a spawn message whose
        // env, argv, cwd, umask, open-files limit and nice are all forged.
        $forger = $this->stub('forger', ['FIXTURE_FORGE_ENV' => '1', 'QUEUE_PREFORK_HANDOFF' => '0'], waitForWorker: false,
            php: ['-d', 'auto_prepend_file='.dirname(self::ARTISAN).'/forge.php'], cwd: $this->dir, shell: 'umask 027; ulimit -Sn 500; ');

        $this->assertTrue($this->eventually(fn () => $this->workerPid('forger') > 0, 8.0), 'the forger got a worker: '.$this->err('forger'));
        $line = (string) preg_replace('/.*^(context=forked [^\n]*).*/ms', '$1', $this->out('forger'));

        $this->assertStringContainsString('config_cache=-', $line, 'no injected environment');
        $this->assertStringContainsString('umask=0027', $line, 'the real umask');
        $this->assertStringContainsString('files=500', $line, 'the real soft limit');
        $this->assertStringContainsString('nice=0', $line);
        $this->assertStringContainsString('cwd='.realpath($this->dir), $line, 'the real working directory');
        $this->assertStringContainsString('"--queue={high},default"', $this->out('forger'), 'the real argv');
        $this->others[$this->workerPid('forger')] = true;
        $this->assertTrue(posix_kill($forger, 0));
    }

    public function test_a_logs_to_stdio_decline_sends_later_stubs_straight_to_stock(): void
    {
        $this->linuxOnly();
        $first = $this->stub('first', ['FIXTURE_DECLINE' => 'logs-to-stdio', 'FIXTURE_LIFETIME' => '0.3'], waitForWorker: false, php: ['-d', 'ffi.enable=0']);
        $this->assertSame(['exit' => 0], $this->waitFor($first, 8.0));
        $this->assertStringContainsString('worker stock pid='.$first, $this->out('first'), 'the declining worker\'s stub ran stock in place');

        $marker = json_decode((string) @file_get_contents((glob($this->runtime.'/*.stock') ?: [''])[0]), true);
        $this->assertSame('logs-to-stdio', $marker['reason'] ?? null);

        $forks = substr_count($this->sharedLog(), 'worker forked'); // the first stub's
        $later = $this->stub('later', ['FIXTURE_DECLINE' => 'logs-to-stdio', 'FIXTURE_LIFETIME' => '0.3'], waitForWorker: false, php: ['-d', 'ffi.enable=0']);
        $this->assertSame(['exit' => 0], $this->waitFor($later, 8.0));
        $this->assertStringContainsString('worker stock pid='.$later, $this->out('later'));
        $this->assertSame($forks, substr_count($this->sharedLog().$this->out('later'), 'worker forked'), 'no fork for the later stub');
        $this->assertStringContainsString('logs to stdout/stderr', $this->err('later'), 'and it says why');
    }

    private function backgroundPid(string $name): int
    {
        $this->assertTrue($this->eventually(fn () => preg_match('/background pid=(\d+)/', $this->out($name)) === 1, 4.0));
        preg_match('/background pid=(\d+)/', $this->out($name), $m);
        $this->others[(int) $m[1]] = true;

        return (int) $m[1];
    }
}
