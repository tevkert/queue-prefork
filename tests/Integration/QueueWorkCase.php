<?php

namespace Tevkert\QueuePrefork\Tests\Integration;

/**
 * Plain `queue:work` under supervisord: the stock and the prefork container run the same
 * `queue-work` programs. Stock is the oracle. In the prefork container each program's pid is a stub, and
 * its worker is forked from the shared template.
 *
 * Needs the stacks with Horizon off and the queue workers on (docker/README.md):
 *   HORIZON=false QUEUE_WORK=true docker compose up -d --no-deps app-stock app-prefork
 * Set HP_LIFECYCLE_LOG to a file to append each scenario's outcomes as JSON lines.
 */
abstract class QueueWorkCase extends IntegrationCase
{
    protected const BOTH = [self::STOCK, self::PREFORK];

    protected const QUEUE = 'default';

    /** QUEUE_WORK_STOPWAIT's default: supervisord's stopwaitsecs for the queue-work programs. */
    protected const STOPWAIT = 10;

    /** @var array<string, int> how many supervisord exit lines each container had when the test began */
    protected array $exitMark = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::BOTH as $container) {
            // The programs exist, stopped, in every stack: only QUEUE_WORK=true starts them with the container.
            if (trim(self::exec($container, 'printenv QUEUE_WORK')[1]) !== 'true' || self::programCount($container) === 0) {
                $this->markTestSkipped('Needs QUEUE_WORK=true (see the class comment)');
            }

            self::supervisorctl($container, "start 'queue-work:*'");
            self::artisan($container, 'up');
            self::artisan($container, 'queue:clear redis --queue='.self::QUEUE.' --force');
            self::artisan($container, 'fixtures:results --reset');
        }

        self::waitBoth(fn ($c) => self::shapeProblem($c) === null, 60, fn ($c) => 'the worker shape: '.self::shapeProblem($c));

        foreach (self::BOTH as $container) {
            $this->exitMark[$container] = count(self::exits($container));
        }
    }

    /**
     * Replaces every worker in both stacks and waits for the new ones. A worker keeps what its jobs did to
     * it: the fork driver's `Concurrency::run` (spatie/fork) leaves TERM handlers that SIGKILL the worker, so
     * a test of how workers stop starts from fresh ones, whatever ran before it.
     */
    protected function freshWorkers(): void
    {
        $before = [self::STOCK => self::workers(self::STOCK), self::PREFORK => self::workers(self::PREFORK)];
        self::both(fn ($c) => self::artisan($c, 'queue:restart'));
        self::waitBoth(fn ($c) => self::gone($c, $before[$c]) && self::shapeProblem($c) === null, 60, 'fresh workers');

        foreach (self::BOTH as $container) {
            $this->exitMark[$container] = count(self::exits($container));
        }
    }

    protected static function programCount(string $container): int
    {
        return (int) preg_match_all('/^queue-work:/m', self::supervisorctl($container, "status 'queue-work:*'"));
    }

    /** @return array<string, int> each RUNNING program's pid (in prefork, its stub's) */
    protected static function programs(string $container): array
    {
        preg_match_all('/^queue-work:(\S+)\s+RUNNING\s+pid (\d+)/m', self::supervisorctl($container, "status 'queue-work:*'"), $m);

        return array_combine($m[1], array_map('intval', $m[2]));
    }

    protected static function programsOfBoth(): array
    {
        return [self::STOCK => self::programs(self::STOCK), self::PREFORK => self::programs(self::PREFORK)];
    }

    /** @return list<array{pid: int, ppid: int, pgid: int, state: string, start: string, cmd: string}> live processes */
    protected static function processes(string $container): array
    {
        $script = 'for p in /proc/[0-9]*; do s=$(cat $p/stat 2>/dev/null) || continue; c=$(tr "\0" " " < $p/cmdline 2>/dev/null); printf "%s\t%s\n" "$s" "$c"; done';
        $processes = [];

        foreach (explode("\n", self::exec($container, $script)[1]) as $line) {
            [$stat, $cmd] = array_pad(explode("\t", $line, 2), 2, '');
            $close = strrpos($stat, ')');
            $fields = $close === false ? [] : explode(' ', substr($stat, $close + 2));

            if (count($fields) < 20 || in_array($fields[0], ['Z', 'X'], true)) {
                continue;
            }

            $processes[] = ['pid' => (int) $stat, 'ppid' => (int) $fields[1], 'pgid' => (int) $fields[2], 'state' => $fields[0], 'start' => $fields[19], 'cmd' => trim($cmd)];
        }

        return $processes;
    }

    protected static function process(string $container, int $pid): ?array
    {
        foreach (self::processes($container) as $process) {
            if ($process['pid'] === $pid) {
                return $process;
            }
        }

        return null;
    }

    protected static function isStub(?array $process): bool
    {
        return $process !== null && (bool) preg_match('#^/bin/sh \S+/stub-[0-9a-f]+\.sh #', $process['cmd']);
    }

    /** A stub's first argument is its worker's pid. */
    protected static function stubWorkerPid(array $stub): int
    {
        return (int) (explode(' ', $stub['cmd'])[2] ?? 0);
    }

    protected static function workers(string $container): array
    {
        return array_values(array_filter(self::processes($container), fn ($p) => str_contains($p['cmd'], 'artisan queue:work') && ! self::isStub($p)));
    }

    protected static function template(string $container): array
    {
        $templates = array_values(array_filter(self::processes($container), fn ($p) => str_contains($p['cmd'], '[qp-template:')));
        self::assertCount(1, $templates, 'one shared template');

        return $templates[0];
    }

    protected static function workerOf(string $container, int $stub): array
    {
        $pid = self::stubWorkerPid(self::process($container, $stub) ?? ['cmd' => '']);

        foreach (self::workers($container) as $worker) {
            if ($worker['pid'] === $pid) {
                return $worker;
            }
        }

        self::fail("no worker {$pid} for stub {$stub}");
    }

    /**
     * null when every program runs with one worker: in stock the program is the worker; in prefork the
     * program is a stub, and its worker a child of the one template. Else what's missing.
     */
    protected static function shapeProblem(string $container): ?string
    {
        $programs = self::programs($container);

        if (count($programs) !== self::programCount($container)) {
            return 'every program RUNNING';
        }

        $processes = self::processes($container);
        $workers = array_column(self::workers($container), null, 'pid');

        if ($container === self::STOCK) {
            return array_diff($programs, array_keys($workers)) === [] ? null : 'each program a worker';
        }

        $templates = array_values(array_filter($processes, fn ($p) => str_contains($p['cmd'], '[qp-template:')));

        if (count($templates) !== 1) {
            return 'one template ('.count($templates).')';
        }

        $byPid = array_column($processes, null, 'pid');

        foreach ($programs as $name => $pid) {
            $worker = isset($byPid[$pid]) && self::isStub($byPid[$pid]) ? ($workers[self::stubWorkerPid($byPid[$pid])] ?? null) : null;

            if ($worker === null || $worker['ppid'] !== $templates[0]['pid']) {
                return "{$name}: a stub whose worker the template forked";
            }
        }

        return count($workers) === count($programs) ? null : 'one worker per program ('.count($workers).'/'.count($programs).')';
    }

    /** No stub that isn't a running program or its status reader; no worker that isn't a stub's. */
    protected static function assertNoStrays(string $container): void
    {
        $programs = self::programs($container);
        $owned = [];

        foreach (self::processes($container) as $process) {
            if (! self::isStub($process)) {
                continue;
            }

            if (in_array($process['pid'], $programs, true)) {
                $owned[] = self::stubWorkerPid($process);
            } else {
                self::assertContains($process['ppid'], $programs, "a stray stub: {$process['pid']} {$process['cmd']}");
            }
        }

        foreach (self::workers($container) as $worker) {
            self::assertContains($worker['pid'], $owned, "a stray worker: {$worker['pid']}");
        }
    }

    /** Two sleepers running in each container; returns each container's workers at that moment. */
    protected static function startSleepers(int $seconds, bool $steady = false): array
    {
        $fixture = $steady ? 'sleeps-steady' : 'sleeps';
        self::both(fn ($c) => self::artisan($c, "fixtures:dispatch {$fixture} --times=2 --sleep={$seconds} --queue=".self::QUEUE));
        self::waitBoth(fn ($c) => count(self::rows($c, 'Sleeps.started')) === 2, 30, 'two sleepers to start');

        return [self::STOCK => self::workers(self::STOCK), self::PREFORK => self::workers(self::PREFORK)];
    }

    protected static function gone(string $container, array $workers): bool
    {
        foreach ($workers as $worker) {
            if (self::alive($container, $worker['pid'], $worker['start'])) {
                return false;
            }
        }

        return true;
    }

    /** Exactly one program has a new pid. */
    protected static function restartedOne(string $container, array $before): bool
    {
        $now = self::programs($container);

        return count($now) === count($before) && count(array_diff_assoc($now, $before)) === 1;
    }

    /** @return list<array> job_results rows of exactly this job name, oldest first */
    protected static function rows(string $container, string $job): array
    {
        $rows = [];

        foreach (explode("\n", self::artisan($container, 'fixtures:results --json --limit=1000 --job='.escapeshellarg($job))) as $line) {
            $row = json_decode($line, true);

            if (is_array($row) && ($row['job'] ?? null) === $job) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected static function failed(string $container): int
    {
        preg_match('/failed_jobs: (\d+)/', self::artisan($container, 'fixtures:results --limit=1'), $m);

        return (int) ($m[1] ?? -1);
    }

    /**
     * Stock must show $expected (rows per job name, and 'failed'); prefork must match stock in those and
     * in how supervisord saw the programs end since the test began.
     *
     * @param  array<string, int>  $expected
     */
    protected function compare(string $scenario, array $expected): void
    {
        $outcomes = [];

        foreach (self::BOTH as $container) {
            foreach (array_keys($expected) as $key) {
                $outcomes[$container][$key] = $key === 'failed' ? self::failed($container) : count(self::rows($container, $key));
            }

            $outcomes[$container]['exits'] = $this->newExits($container);
        }

        if (($log = getenv('HP_LIFECYCLE_LOG')) !== false && $log !== '') {
            file_put_contents($log, json_encode(['scenario' => $scenario, 'outcomes' => $outcomes])."\n", FILE_APPEND);
        }

        $this->assertSame($expected, array_diff_key($outcomes[self::STOCK], ['exits' => true]), "{$scenario}: stock (the oracle)");
        $this->assertSame($outcomes[self::STOCK], $outcomes[self::PREFORK], "{$scenario}: prefork matches stock");
    }

    /** @return list<string> supervisord's exit lines for the queue-work programs, as "exited: exit status 0; expected" */
    protected static function exits(string $container): array
    {
        $process = proc_open(['docker', 'logs', $container], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        preg_match_all('/ (exited|stopped): queue-work_\d+ \(([^)]*)\)/', $output, $m, PREG_SET_ORDER);

        return array_map(fn ($line) => "{$line[1]}: {$line[2]}", $m);
    }

    /** @return list<string> the exit lines since the test began, sorted */
    protected function newExits(string $container): array
    {
        $exits = array_slice(self::exits($container), $this->exitMark[$container]);
        sort($exits);

        return $exits;
    }

    protected static function both(callable $action): void
    {
        foreach (self::BOTH as $container) {
            $action($container);
        }
    }

    protected static function waitBoth(callable $check, float $timeout, string|\Closure $what): void
    {
        foreach (self::BOTH as $container) {
            self::waitUntil(fn () => $check($container), $timeout, is_string($what) ? "{$container}: {$what}" : fn () => "{$container}: ".$what($container));
        }
    }
}
