<?php

namespace Tevkert\QueuePrefork\Tests\Mechanics;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Identity;

/**
 * The per-worker fork end to end, without Laravel: a fixture `artisan`
 * loads the package's autoloader, so the hook hands it to a real shared template, and the forked child
 * behaves like a small worker (tests/Fixtures/queue-app/artisan, driven by FIXTURE_* variables). Each
 * test gets its own runtime dir (XDG_RUNTIME_DIR).
 */
abstract class QueueCase extends TestCase
{
    protected const ARTISAN = __DIR__.'/../Fixtures/queue-app/artisan';

    protected string $dir;

    protected string $runtime;

    /** @var array<int, true> stubs we started (our children) */
    protected array $stubs = [];

    /** @var array<int, true> other processes to clean up (workers, templates) */
    protected array $others = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/qpt-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir, 0700);
        $this->runtime = $this->dir.'/qp-'.posix_geteuid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->runtime.'/*.pid') ?: [] as $pidFile) {
            $this->others[(int) file_get_contents($pidFile)] = true;
        }

        foreach (array_keys($this->stubs) as $pid) {
            @posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        foreach (array_keys($this->others) as $pid) {
            if ($pid > 1) {
                @posix_kill($pid, SIGKILL);
            }
        }

        exec('rm -rf '.escapeshellarg($this->dir));
    }

    protected function linuxOnly(string $why = 'shared-log mode runs on Linux only'): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped($why);
        }
    }

    /**
     * Starts `php artisan queue:work` with stdout/stderr in files and, like a process manager, nothing
     * else open (unless $crowded: then fds 3–12 are taken, leaving the stub script no descriptor); returns its pid.
     * $shell runs first in the launching shell (`ulimit -n 48; `, say).
     */
    protected function stub(string $name, array $env = [], bool $waitForWorker = true, bool $crowded = false, array $php = [], ?string $cwd = null, array $args = [], string $shell = ''): int
    {
        $env = array_merge(getenv(), ['XDG_RUNTIME_DIR' => $this->dir, 'FIXTURE_NAME' => $name], $env);
        $out = "{$this->dir}/{$name}.out";
        $err = "{$this->dir}/{$name}.err";
        $pid = pcntl_fork();

        if ($pid === 0) {
            foreach (\Tevkert\QueuePrefork\Support\Audit::openFds() as $fd) {
                if ($fd > 2) {
                    \Tevkert\QueuePrefork\Support\Native::get()->close($fd);
                }
            }

            if ($cwd !== null) {
                chdir($cwd);
            }

            if ($crowded) {
                for ($fd = 3; $fd <= 12; $fd++) {
                    $GLOBALS['__crowd'][] = fopen('/dev/null', 'r');
                }
            }

            pcntl_exec('/bin/sh', ['-c', $shell.'exec "$@" >'.escapeshellarg($out).' 2>'.escapeshellarg($err), 'sh', PHP_BINARY, ...$php, self::ARTISAN, 'queue:work', '--queue={high},default', ...$args], $env);
            posix_kill(getmypid(), SIGKILL);
        }

        $this->stubs[$pid] = true;

        if ($waitForWorker) {
            $this->assertTrue($this->eventually(fn () => $this->workerPid($name) > 0, 6.0), "no forked worker for {$name}: ".@file_get_contents($err).' / '.@file_get_contents($out));
            $this->others[$this->workerPid($name)] = true;
        }

        return $pid;
    }

    protected function workerPid(string $name): int
    {
        // Its own line: the shared log holds every stub's worker (the warm-up boot prints `warmup`).
        preg_match('/^worker forked pid=(\d+) .* name='.preg_quote($name, '/').'$/m', $this->out($name)."\n".$this->sharedLog(), $m);

        return (int) ($m[1] ?? 0);
    }

    protected function templatePid(): int
    {
        $files = glob($this->runtime.'/*.pid') ?: [];
        $this->assertCount(1, $files);
        $pid = (int) file_get_contents($files[0]);
        $this->others[$pid] = true;

        return $pid;
    }

    protected function request(array $message): ?array
    {
        $socket = (glob($this->runtime.'/*.sock') ?: [null])[0];
        $stream = stream_socket_client('unix://'.$socket, $errno, $error, 2.0);
        fwrite($stream, json_encode($message)."\n");
        $reply = fgets($stream);
        fclose($stream);

        return $reply === false ? null : json_decode($reply, true);
    }

    /** The template's own log, `<key16>.log` in the runtime dir (the first one, if there are several). */
    protected function templateLog(): string
    {
        $logs = array_values(array_filter(glob($this->runtime.'/*.log') ?: [], fn ($log) => ! str_starts_with(basename($log), 'queue-prefork-')));

        return $logs === [] ? '' : (string) @file_get_contents($logs[0]);
    }

    /** Shared-log mode's output file (the fixture release has no storage/logs, so the runtime dir). */
    protected function sharedLog(): string
    {
        $logs = glob($this->runtime.'/queue-prefork-*.log') ?: [];

        return $logs === [] ? '' : (string) @file_get_contents($logs[0]);
    }

    protected function out(string $name): string
    {
        return (string) @file_get_contents("{$this->dir}/{$name}.out");
    }

    protected function err(string $name): string
    {
        return (string) @file_get_contents("{$this->dir}/{$name}.err");
    }

    protected function commandOf(int $pid): string
    {
        return PHP_OS_FAMILY === 'Linux'
            ? str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline"))
            : (string) shell_exec('ps -o command= -p '.$pid);
    }

    /** @return array{exit: int}|array{signal: int}|array{timeout: true} */
    protected function waitFor(int $pid, float $seconds): array
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if (pcntl_waitpid($pid, $status, WNOHANG) === $pid) {
                unset($this->stubs[$pid]);

                return pcntl_wifsignaled($status) ? ['signal' => pcntl_wtermsig($status)] : ['exit' => pcntl_wexitstatus($status)];
            }

            usleep(20000);
        }

        return ['timeout' => true];
    }

    protected function eventually(callable $check, float $seconds = 3.0): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            Identity::forgetCache();

            if ($check()) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }
}
