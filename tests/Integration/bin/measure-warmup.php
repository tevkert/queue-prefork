<?php

// Warm-up measurement, run inside the prefork app container after the soak:
//   docker exec queue-prefork-app-prefork-1 php /repo/tests/Integration/bin/measure-warmup.php [workers]
// Starts N idle `queue:work` processes on an unused queue three ways — stock, prefork without the
// warm-up boot, prefork with it — waits for them to settle, and prints each tree's total PSS (workers,
// their template, stubs). Every process it starts is killed by pid at the end.

$workers = (int) ($argv[1] ?? 4);
// Each prefork case gets a key of its own, so it starts a cold template (QUEUE_PREFORK_LOG_FILES only
// matters in shared-log mode); stop the container's queue-work programs first for less noise.
$cases = [
    'stock' => ['QUEUE_PREFORK' => '0'],
    'no-warmup' => ['QUEUE_PREFORK_WARMUP' => '0', 'QUEUE_PREFORK_LOG_FILES' => '7'],
    'warmup' => ['QUEUE_PREFORK_WARMUP' => '1', 'QUEUE_PREFORK_LOG_FILES' => '8'],
];

function pss(int $pid): int
{
    return preg_match('/^Pss:\s+(\d+)/m', (string) @file_get_contents("/proc/{$pid}/smaps_rollup"), $m) ? (int) $m[1] : 0;
}

function processes(): array
{
    $rows = [];

    foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
        $stat = (string) @file_get_contents("{$dir}/stat");
        $after = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
        $rows[(int) basename($dir)] = ['ppid' => (int) ($after[1] ?? 0), 'cmd' => trim(str_replace("\0", ' ', (string) @file_get_contents("{$dir}/cmdline")))];
    }

    return $rows;
}

foreach ($cases as $name => $env) {
    $queue = 'qp-measure-'.$name.'-'.getmypid();
    $started = [];

    for ($i = 0; $i < $workers; $i++) {
        $spec = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open(['php', 'artisan', 'queue:work', 'redis', '--queue='.$queue, '--sleep=3'], $spec, $pipes, '/repo/workbench', array_merge(getenv(), $env));
        $started[] = proc_get_status($process)['pid'];
    }

    sleep(25);
    $all = processes();
    $tree = [];

    foreach ($all as $pid => $p) {
        if (str_contains($p['cmd'], $queue) && ! str_starts_with($p['cmd'], '/bin/sh')) {
            $tree[$pid] = 'worker';
            $tree[$p['ppid']] = isset($all[$p['ppid']]) && str_contains($all[$p['ppid']]['cmd'], 'qp-template') ? 'template' : ($tree[$p['ppid']] ?? null);
        }
    }

    foreach ($all as $pid => $p) {
        if (in_array($pid, $started, true) || in_array($p['ppid'], $started, true)) {
            $tree[$pid] ??= 'stub';
        }
    }

    $tree = array_filter($tree);
    $kb = array_sum(array_map('pss', array_keys($tree)));
    $counts = array_count_values($tree);
    printf("%-10s %6.1f MB  (%s)\n", $name, $kb / 1024, implode(', ', array_map(fn ($r, $n) => "{$n} {$r}", array_keys($counts), $counts)));

    foreach (array_keys($tree) as $pid) {
        if ($tree[$pid] !== 'template') {
            @posix_kill($pid, SIGKILL);
        }
    }

    foreach (array_keys($tree) as $pid) {
        if ($tree[$pid] === 'template') {
            @posix_kill($pid, SIGTERM); // it retires and exits once its children are gone
        }
    }

    sleep(2);
}
