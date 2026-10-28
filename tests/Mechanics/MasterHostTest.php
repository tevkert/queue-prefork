<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Identity;

/**
 * The Horizon master forked from a host template, without Laravel: a fake
 * `artisan horizon` (tests/Fixtures/fake-master.php) loads the package's autoloader, so the hook execs it
 * into a real host template that forks the master, keeping the pid supervisord would watch.
 */
class MasterHostTest extends TestCase
{
    private const MASTER = __DIR__.'/../Fixtures/fake-master.php';

    private string $dir;

    /** @var array<int, true> */
    private array $started = [];

    /** @var array<int, true> */
    private array $others = [];

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('host mode runs on Linux only (MasterHandoff::reasons)');
        }

        $this->dir = sys_get_temp_dir().'/qpm-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->others) as $pid) {
            @posix_kill($pid, SIGKILL);
        }

        foreach (array_keys($this->started) as $pid) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        if (isset($this->dir)) {
            exec('rm -rf '.escapeshellarg($this->dir));
        }
    }

    public function test_the_program_pid_becomes_the_host_and_forks_the_master(): void
    {
        $host = $this->start('one');
        $master = $this->masterPid('one');

        $this->assertMatchesRegularExpression("/^hosted master pid={$master} ppid={$host} host={$host} /m", $this->out('one'), 'the master is the host\'s child');
        $this->assertStringContainsString('fake-master.php horizon', $this->commandOf($master), 'the master shows the stock command line');
        $this->assertStringContainsString('hp-template:host', $this->commandOf($host), 'the started pid became the host template');
        $this->assertStringNotContainsString('horizon', str_replace('hp-template', '', $this->commandOf($host)), 'horizon:purge must never match the host');
        $this->assertStringContainsString("ready from={$host} host=true", $this->out('one'));
    }

    public function test_signals_reach_the_master_and_the_host_exits_as_it_does(): void
    {
        $host = $this->start('relay', ['FIXTURE_TERM_EXIT' => '9']);
        $this->masterPid('relay');

        posix_kill($host, SIGUSR2);
        $this->assertTrue($this->eventually(fn () => str_contains($this->out('relay'), 'USR2 received')), 'USR2 relayed');

        posix_kill($host, SIGTERM);
        $this->assertSame(['exit' => 9], $this->waitFor($host, 5.0), 'the host exits with the master\'s code');
        $this->assertStringContainsString('TERM received', $this->out('relay'));
    }

    public function test_a_master_killed_by_a_signal_takes_the_host_with_the_same_signal(): void
    {
        $host = $this->start('killed');
        posix_kill($this->masterPid('killed'), SIGKILL);
        $this->assertSame(['signal' => SIGKILL], $this->waitFor($host, 5.0));

        $host = $this->start('termed', ['FIXTURE_NO_TERM_HANDLER' => '1']);
        $this->masterPid('termed');
        posix_kill($host, SIGTERM); // relayed; the master dies of it
        $this->assertSame(['signal' => SIGTERM], $this->waitFor($host, 5.0));
    }

    public function test_core_and_interrupt_signals_end_the_host_as_they_end_the_master(): void
    {
        $host = $this->start('quit');
        $this->masterPid('quit');
        posix_kill($host, SIGQUIT); // relayed; a core signal becomes 128+n, as in bin/stub.sh
        $this->assertSame(['exit' => 128 + SIGQUIT], $this->waitFor($host, 5.0));

        foreach ([SIGINT, SIGHUP] as $signal) {
            $host = $this->start("sig{$signal}", ['FIXTURE_NO_TERM_HANDLER' => '1']);
            $this->masterPid("sig{$signal}");
            posix_kill($host, $signal);
            $this->assertSame(['signal' => $signal], $this->waitFor($host, 5.0), "signal {$signal}");
        }
    }

    public function test_signals_the_program_ignores_stay_ignored_in_the_host_and_the_master(): void
    {
        $host = $this->start('nohup', ignore: [SIGHUP]);
        $master = $this->masterPid('nohup');
        $this->assertMatchesRegularExpression('/^hosted master .* sigign=[0-9a-f]*[13579bdf]$/m', $this->out('nohup'), 'the master ignores HUP');

        posix_kill($host, SIGHUP); // relayed: the master ignores it, as a stock master would
        usleep(300000);
        $this->assertTrue(Identity::alive($host, null) && Identity::alive($master, null), 'HUP is not fatal');
        $this->assertSame(['timeout' => true], $this->waitFor($host, 0.1));
    }

    public function test_a_handler_the_master_installs_overrides_an_inherited_ignore(): void
    {
        $host = $this->start('term-ignored', ignore: [SIGTERM]); // the fake master installs its TERM handler
        $this->masterPid('term-ignored');

        posix_kill($host, SIGTERM);
        $this->assertSame(['exit' => 7], $this->waitFor($host, 5.0), 'the master\'s TERM handler ran');
    }

    public function test_a_master_switched_off_after_boot_hands_back_and_the_stock_master_runs_in_place(): void
    {
        $host = $this->start('off', ['FIXTURE_STOCK' => '1']);

        $this->assertTrue($this->eventually(fn () => str_contains($this->out('off'), "stock master pid={$host} "), 8.0), 'the stock master, in the program\'s pid: '.$this->out('off').$this->err('off'));
        $this->assertStringContainsString('the Horizon master runs as before: fixture', $this->err('off'));
        posix_kill($host, SIGTERM);
        $this->assertSame(['exit' => 7], $this->waitFor($host, 5.0));
    }

    public function test_a_stop_while_the_master_hands_back_ends_the_program_instead(): void
    {
        $host = $this->start('stopped', ['FIXTURE_STOCK' => 'stopped']);

        $this->assertSame(['exit' => 0], $this->waitFor($host, 8.0), $this->out('stopped').$this->err('stopped'));
        $this->assertStringNotContainsString('stock master', $this->out('stopped'), 'no stock master after the stop');
    }

    public function test_the_switches_start_the_master_as_before(): void
    {
        foreach (['QUEUE_PREFORK_HANDOFF' => '0', 'QUEUE_PREFORK_HORIZON' => 'false', 'QUEUE_PREFORK' => '0'] as $name => $value) {
            $host = $this->start($name, [$name => $value]);

            $this->assertTrue($this->eventually(fn () => str_contains($this->out($name), "stock master pid={$host} "), 5.0), $name);
            $this->assertStringNotContainsString('hp-template', $this->commandOf($host), $name);
        }
    }

    public function test_an_unsafe_host_starts_the_stock_master(): void
    {
        $host = $this->start('unsafe', socket: true); // a socket inherited at fd 3: the host's audit refuses it

        $this->assertTrue($this->eventually(fn () => str_contains($this->out('unsafe'), "stock master pid={$host} "), 8.0), $this->err('unsafe'));
        $this->assertStringContainsString('unsafe template', $this->err('unsafe'));
        $this->assertStringContainsString('fd 3', $this->err('unsafe'));
    }

    public function test_the_host_takes_its_opcache_size_from_the_environment(): void
    {
        $this->start('opcache', ['QUEUE_PREFORK_OPCACHE_MB' => '64']);
        $this->masterPid('opcache');

        $this->assertTrue($this->eventually(fn () => str_contains($this->out('opcache'), 'opcache_mb=')), $this->out('opcache'));
        preg_match('/opcache_mb=(\d+)/', $this->out('opcache'), $m);
        $this->assertGreaterThan(48, (int) $m[1]);
        $this->assertLessThanOrEqual(64, (int) $m[1]);
    }

    public function test_units_fork_from_the_host_with_the_masters_settings(): void
    {
        $events = $this->dir.'/events.jsonl';
        touch($events);
        $host = $this->start('units', ['FIXTURE_UNIT' => '1', 'FIXTURE_OUT' => $events]);
        $this->masterPid('units');

        $this->assertTrue($this->eventually(fn () => preg_match('/unit spawned pid=(\d+)/', $this->out('units')) === 1, 10.0), $this->out('units').$this->err('units'));
        preg_match('/unit spawned pid=(\d+)/', $this->out('units'), $m);
        $server = (int) $m[1];
        $this->others[$server] = true;

        $this->assertSame($host, (int) Identity::ppid($server), 'the fork server is the host\'s child, beside the master');
        $this->assertTrue($this->eventually(fn () => str_contains((string) file_get_contents($events), '"e":"loop-started"'), 10.0), 'the unit\'s loop started');

        $started = json_decode(strtok((string) file_get_contents($events), "\n"), true);
        $this->assertSame(5, $started['config']['exit_grace'] ?? null, 'the master\'s exit grace reached the unit');
        $this->assertEquals(10, $started['config']['hello_timeout'] ?? null);
        $this->assertSame(sys_get_temp_dir().'/qp-fake-master-'.$this->masterPid('units'), $started['status_dir'] ?? null);
    }

    // --- helpers -------------------------------------------------------------------------------------

    /** Starts `php fake-master.php horizon` as a process manager would; returns its pid. */
    private function start(string $name, array $env = [], bool $socket = false, array $ignore = []): int
    {
        $env = array_merge(getenv(), ['XDG_RUNTIME_DIR' => $this->dir], $env);
        $out = "{$this->dir}/{$name}.out";
        $err = "{$this->dir}/{$name}.err";
        $pid = pcntl_fork();

        if ($pid === 0) {
            foreach (\Tevkert\QueuePrefork\Support\Audit::openFds() as $fd) {
                if ($fd > 2) {
                    \Tevkert\QueuePrefork\Support\Native::get()->close($fd);
                }
            }

            if ($socket) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP); // fds 3 and 4
                fclose($pair[1]);
                $GLOBALS['__held'] = $pair[0]; // only fd 3 is left, its peer closed
            }

            foreach ($ignore as $signal) {
                pcntl_signal($signal, SIG_IGN); // as nohup or a background job leaves it
            }

            pcntl_exec('/bin/sh', ['-c', 'exec "$@" >'.escapeshellarg($out).' 2>'.escapeshellarg($err), 'sh', PHP_BINARY, self::MASTER, 'horizon'], $env);
            posix_kill(getmypid(), SIGKILL);
        }

        $this->started[$pid] = true;

        return $pid;
    }

    private function masterPid(string $name): int
    {
        $this->assertTrue($this->eventually(fn () => preg_match('/^hosted master pid=(\d+)/m', $this->out($name)) === 1, 8.0), "no hosted master for {$name}: ".$this->out($name).$this->err($name));
        preg_match('/^hosted master pid=(\d+)/m', $this->out($name), $m);
        $this->others[(int) $m[1]] = true;

        return (int) $m[1];
    }

    private function out(string $name): string
    {
        return (string) @file_get_contents("{$this->dir}/{$name}.out");
    }

    private function err(string $name): string
    {
        return (string) @file_get_contents("{$this->dir}/{$name}.err");
    }

    private function commandOf(int $pid): string
    {
        return PHP_OS_FAMILY === 'Linux'
            ? str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"))
            : (string) shell_exec('ps -o command= -p '.$pid);
    }

    /** @return array{exit: int}|array{signal: int}|array{timeout: true} */
    private function waitFor(int $pid, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                unset($this->started[$pid]);

                return pcntl_wifsignaled($status) ? ['signal' => pcntl_wtermsig($status)] : ['exit' => pcntl_wexitstatus($status)];
            }

            usleep(20000);
        }

        return ['timeout' => true];
    }

    private function eventually(callable $check, float $seconds = 3.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($check()) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }
}
