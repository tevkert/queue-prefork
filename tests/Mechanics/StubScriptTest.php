<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Fd;
use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Native;

/**
 * bin/stub.sh under every POSIX shell available here: the stub runs in its own
 * process group, as under supervisord; this test plays the template (one end of the connection) and
 * owns the worker.
 */
class StubScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../bin/stub.sh';

    private string $runtime;

    /** @var array<int, true> */
    private array $children = [];

    public static function shells(): array
    {
        $shells = [];

        foreach (['sh' => ['/bin/sh'], 'dash' => ['/bin/dash'], 'bash' => ['/bin/bash'], 'bash posix' => ['/bin/bash', '--posix'], 'busybox' => ['/bin/busybox', 'sh']] as $name => $argv) {
            if (is_executable($argv[0])) {
                $shells[$name] = [$argv];
            }
        }

        return $shells;
    }

    protected function setUp(): void
    {
        $this->runtime = sys_get_temp_dir().'/qp-stub-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->runtime, 0700);

        if (! Native::available()) {
            $this->markTestSkipped('needs FFI to place the connection on fd 3: '.Native::reason());
        }
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->children) as $pid) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        foreach (glob($this->runtime.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->runtime);
    }

    #[DataProvider('shells')]
    public function test_a_group_term_reaches_the_worker_once_and_the_stub_outlives_its_drain(array $shell): void
    {
        $worker = $this->worker(drain: 0.7, exit: 7);
        [$stub, $t] = $this->stub($shell, $worker, 1);

        posix_kill(-$stub, SIGTERM); // stopasgroup / systemd: the whole group
        usleep(300000);
        $this->assertSame(0, pcntl_waitpid($stub, $status, WNOHANG), 'the stub must not exit while the worker drains');

        $this->assertSame(7, $this->reap($worker));
        $this->assertSame(1, (int) @file_get_contents($this->runtime.'/term-count'), 'TERM forwarded exactly once');
        fwrite($t, "7\n");

        $this->assertSame(['exit' => 7], $this->waitFor($stub, 3.0));
    }

    #[DataProvider('shells')]
    public function test_a_term_right_after_go_is_forwarded(array $shell): void
    {
        $worker = $this->worker(drain: 0.2, exit: 7);
        [$stub, $t] = $this->stub($shell, $worker, 9);

        posix_kill($stub, SIGTERM); // the instant the template could admit the worker

        $this->assertSame(7, $this->reap($worker), 'the worker drained instead of the stub dying');
        fwrite($t, "7\n");
        $this->assertSame(['exit' => 7], $this->waitFor($stub, 3.0));
    }

    #[DataProvider('shells')]
    public function test_an_individual_signal_is_forwarded(array $shell): void
    {
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 2);

        posix_kill($stub, SIGUSR2);
        $this->assertTrue($this->eventually(fn () => file_exists($this->runtime.'/usr2')), 'USR2 reached the worker');

        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, "0\n");
        $this->assertSame(['exit' => 0], $this->waitFor($stub, 3.0));
    }

    #[DataProvider('shells')]
    public function test_a_signal_death_is_re_raised_and_a_core_signal_becomes_an_exit_code(array $shell): void
    {
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 3);
        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, 's'.SIGTERM."\n");
        $this->assertSame(['signal' => SIGTERM], $this->waitFor($stub, 3.0));

        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 4);
        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, 's'.SIGSEGV."\n");
        $this->assertSame(['exit' => 128 + SIGSEGV], $this->waitFor($stub, 3.0), 'no core dump of the shell');
    }

    #[DataProvider('shells')]
    public function test_a_stock_status_execs_the_original_command_in_place(array $shell): void
    {
        $out = $this->runtime.'/stock-out';
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 5, ['/bin/sh', '-c', 'printf "%s %s" "$QUEUE_PREFORK_HANDOFF" "$$" > '.escapeshellarg($out).'; exit 5']);
        $this->assertFileDoesNotExist($this->runtime.'/req-5.argv', 'the stub loads its command line at startup');

        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, "stock\n");

        $this->assertSame(['exit' => 5], $this->waitFor($stub, 3.0));
        $this->assertSame("0 {$stub}", file_get_contents($out), 'the handoff is disabled and the pid is kept');
    }

    #[DataProvider('shells')]
    public function test_a_stock_status_without_a_command_line_exits_70(array $shell): void
    {
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 6, []);
        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, "stock\n");

        $this->assertSame(['exit' => 70], $this->waitFor($stub, 3.0));
    }

    #[DataProvider('shells')]
    public function test_when_the_template_dies_the_stub_waits_for_the_worker(array $shell): void
    {
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 7);

        fclose($t); // EOF: no status will ever come
        usleep(1500000);
        $this->assertSame(0, pcntl_waitpid($stub, $status, WNOHANG), 'the stub never exits while its worker lives');

        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        $this->assertSame(['exit' => 1], $this->waitFor($stub, 4.0));
    }

    #[DataProvider('shells')]
    public function test_a_recycled_pid_is_never_signalled(array $shell): void
    {
        $worker = $this->worker();
        [$stub, $t] = $this->stub($shell, $worker, 8, null, 'not-the-start-time');

        posix_kill($stub, SIGTERM);
        usleep(500000);
        $this->assertTrue(Identity::alive($worker, Identity::startTime($worker)), 'a mismatched identity is not signalled');

        posix_kill($worker, SIGKILL);
        $this->reap($worker);
        fwrite($t, "0\n");
        $this->assertSame(['exit' => 0], $this->waitFor($stub, 3.0));
    }

    /** A worker that counts TERMs, drains for $drain seconds on TERM, and marks USR2. */
    private function worker(float $drain = 0.0, int $exit = 0): int
    {
        $runtime = $this->runtime;
        $pid = pcntl_fork();

        if ($pid === 0) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use ($runtime, $drain, $exit): void {
                $count = (int) @file_get_contents($runtime.'/term-count');
                file_put_contents($runtime.'/term-count', (string) ($count + 1));
                usleep((int) ($drain * 1e6));
                $this->quit($exit);
            });
            pcntl_signal(SIGUSR2, fn () => touch($runtime.'/usr2'));

            while (true) {
                usleep(20000);
            }
        }

        $this->children[$pid] = true;
        usleep(50000);

        return $pid;
    }

    /** Leaves the forked child without running PHPUnit's shutdown. */
    private function quit(int $code): never
    {
        pcntl_exec('/bin/sh', ['-c', 'exit '.$code]);
        exit($code);
    }

    /**
     * Starts the stub in its own process group with the connection on fd 3.
     *
     * @param  list<string>|null  $argv  the original command line written for the stock exec (null: a placeholder)
     * @return array{0: int, 1: resource} the stub's pid and the template's end of the connection
     */
    private function stub(array $shell, int $worker, int $id, ?array $argv = null, ?string $start = null): array
    {
        $argv ??= ['/bin/true', 'placeholder'];
        file_put_contents($this->runtime."/req-{$id}.argv", 'set --'.($argv === [] ? '' : ' '.implode(' ', array_map('escapeshellarg', $argv)))."\n");
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $start ??= (string) Identity::startTime($worker);
        $pid = pcntl_fork();

        if ($pid === 0) {
            posix_setpgid(0, 0);
            $mine = Fd::number($pair[1]);
            fclose($pair[0]); // the stub must never hold the template's end
            Native::get()->dup2($mine, 3);
            Native::get()->close($mine);
            $args = array_slice($shell, 1);
            pcntl_exec($shell[0], [...$args, self::SCRIPT, (string) $worker, $start, (string) $id, '3', $this->runtime]);
            posix_kill(getmypid(), SIGKILL);
        }

        fclose($pair[1]);
        $this->children[$pid] = true;
        stream_set_timeout($pair[0], 3);
        $this->assertSame("{\"t\":\"go\"}\n", fgets($pair[0]), 'the stub releases its worker once its traps are set');

        return [$pid, $pair[0]];
    }

    private function reap(int $pid): int
    {
        pcntl_waitpid($pid, $status);
        unset($this->children[$pid]);

        return pcntl_wifexited($status) ? pcntl_wexitstatus($status) : 128 + pcntl_wtermsig($status);
    }

    /** @return array{exit: int}|array{signal: int}|array{timeout: true} */
    private function waitFor(int $pid, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                unset($this->children[$pid]);

                return pcntl_wifsignaled($status) ? ['signal' => pcntl_wtermsig($status)] : ['exit' => pcntl_wexitstatus($status)];
            }

            usleep(20000);
        }

        return ['timeout' => true];
    }

    private function eventually(callable $check, float $seconds = 2.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($check()) {
                return true;
            }

            usleep(20000);
        }

        return false;
    }
}
