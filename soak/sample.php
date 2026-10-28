<?php

/*
 * Soak sampler. Runs inside an app container (supervisord program `soak-sample`, SOAK_SAMPLE=true)
 * and every --interval seconds appends:
 *
 *   <out>/<mode>/processes.csv   one row per PHP process or stub (this sampler excluded): pid, ppid, role,
 *                                /proc/<pid>/smaps_rollup sizes (kB), CPU seconds, cmdline
 *   <out>/<mode>/cgroup.csv      one row for the container: memory.current, memory.stat anon / file /
 *                                shmem, CPU, Horizon throughput (jobs/min) and queue backlog
 *
 * Roles, from the cmdline (titles included): template (contains "hp-template" or "qp-template"),
 * stub (a queue-prefork stub script, with its status reader; the only non-PHP rows), fork-server
 * (horizon:supervisor ... [fork-server]), supervisor (horizon:supervisor), worker (horizon:work or
 * queue:work), dispatcher (soak:dispatch), master (a "horizon" argument and no other horizon:* command),
 * other.
 *
 * Throughput comes from the `soak:stats` hash the app's SoakStats listener keeps in Redis
 * (REDIS_HOST / REDIS_PORT / REDIS_DB / REDIS_PREFIX, as the app container sets them).
 *
 * Usage: php soak/sample.php [--interval=60] [--once] [--mode=stock] [--out=/repo/soak/results]
 * Plain PHP: no Composer, no framework. Linux only (/proc, cgroup v2).
 */

const PROCESS_COLUMNS = [
    'timestamp', 'unix', 'pid', 'ppid', 'start_ticks', 'age_s', 'role',
    'rss_kb', 'pss_kb', 'pss_anon_kb', 'pss_file_kb', 'pss_shmem_kb',
    'shared_clean_kb', 'shared_dirty_kb', 'private_clean_kb', 'private_dirty_kb', 'swap_kb',
    'cpu_s', 'cmdline',
];

const CGROUP_COLUMNS = [
    'timestamp', 'unix', 'memory_current_kb', 'anon_kb', 'file_kb', 'shmem_kb', 'file_mapped_kb',
    'kernel_kb', 'cpu_s', 'cpu_pct', 'php_processes', 'php_pss_kb',
    'processed_total', 'failed_total', 'jobs_per_min', 'failed_per_min', 'runtime_ms_avg', 'pending',
    'swap_kb', 'memory_max_events', 'oom_kills',
];

const SMAPS_FIELDS = [
    'Rss' => 'rss_kb', 'Pss' => 'pss_kb', 'Pss_Anon' => 'pss_anon_kb', 'Pss_File' => 'pss_file_kb',
    'Pss_Shmem' => 'pss_shmem_kb', 'Shared_Clean' => 'shared_clean_kb', 'Shared_Dirty' => 'shared_dirty_kb',
    'Private_Clean' => 'private_clean_kb', 'Private_Dirty' => 'private_dirty_kb', 'Swap' => 'swap_kb',
];

const QUEUES = ['import', 'media', 'default', 'ai', 'scout', 'notify', 'maintenance', 'digest'];

const CLK_TCK = 100; // USER_HZ on Linux

$options = getopt('', ['interval:', 'once', 'mode:', 'out:']);
$interval = max(1, (int) ($options['interval'] ?? 60));
$mode = (string) ($options['mode'] ?? getenv('SOAK_MODE') ?: 'stock');
$dir = rtrim((string) ($options['out'] ?? '/repo/soak/results'), '/').'/'.$mode;

if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
    fwrite(STDERR, "sample: cannot create {$dir}\n");
    exit(1);
}

$state = ['cpu_usec' => null, 'cpu_at' => null, 'stats' => null, 'stats_at' => null];

while (true) {
    $now = microtime(true);
    $summary = sample($dir, $now, $state);
    fwrite(STDOUT, $summary."\n");

    if (isset($options['once'])) {
        break;
    }

    // Next multiple of the interval, so rows from both containers line up in time. Counted from at least
    // half an interval after this sample began: a wake-up that came a little early would otherwise
    // finish before the boundary, pick the same boundary again and sample twice under one label.
    $next = (floor(max(microtime(true), $now + $interval / 2) / $interval) + 1) * $interval;
    time_sleep_until($next);
}

function sample(string $dir, float $now, array &$state): string
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z', (int) round($now));
    $unix = (int) round($now);
    $uptime = (float) explode(' ', (string) @file_get_contents('/proc/uptime'))[0];
    $self = getmypid();
    $rows = [];

    foreach (scandir('/proc') ?: [] as $entry) {
        if (! ctype_digit($entry) || (int) $entry === $self) {
            continue;
        }

        $row = process((int) $entry, $uptime);

        if ($row !== null) {
            $rows[] = ['timestamp' => $timestamp, 'unix' => $unix] + $row;
        }
    }

    usort($rows, fn ($a, $b) => [$a['role'], $a['pid']] <=> [$b['role'], $b['pid']]);
    append($dir.'/processes.csv', PROCESS_COLUMNS, $rows);

    $cgroup = cgroup($now, $state);
    $throughput = throughput($now, $state);
    $pss = array_sum(array_column($rows, 'pss_kb'));

    append($dir.'/cgroup.csv', CGROUP_COLUMNS, [[
        'timestamp' => $timestamp,
        'unix' => $unix,
        ...$cgroup,
        'php_processes' => count($rows),
        'php_pss_kb' => $pss,
        ...$throughput,
    ]]);

    $byRole = [];

    foreach ($rows as $row) {
        $byRole[$row['role']] = ($byRole[$row['role']] ?? 0) + $row['pss_kb'];
    }

    ksort($byRole);

    return sprintf(
        '[%s] %s: %d php processes, PSS %.1f MB (%s); cgroup %s MB; %s jobs/min',
        $timestamp,
        basename($dir),
        count($rows),
        $pss / 1024,
        implode(', ', array_map(fn ($role, $kb) => sprintf('%s %.1f', $role, $kb / 1024), array_keys($byRole), $byRole)),
        $cgroup['memory_current_kb'] === '' ? '?' : sprintf('%.1f', $cgroup['memory_current_kb'] / 1024),
        $throughput['jobs_per_min'] === '' ? '-' : $throughput['jobs_per_min'],
    );
}

/** @return array<string, int|float|string>|null one row, or null if the process isn't PHP or is gone */
function process(int $pid, float $uptime): ?array
{
    $raw = @file_get_contents("/proc/{$pid}/cmdline");

    if ($raw === false || $raw === '') {
        return null; // kernel thread, zombie, or gone
    }

    $cmdline = trim(str_replace("\0", ' ', $raw));

    if (! isPhp($pid, $cmdline) && ! isStub($cmdline)) {
        return null;
    }

    $stat = @file_get_contents("/proc/{$pid}/stat");
    $smaps = @file_get_contents("/proc/{$pid}/smaps_rollup");

    if ($stat === false || $smaps === false) {
        return null;
    }

    // Fields after the last ')': state is field 3, so index = field - 3.
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    $start = (int) $fields[22 - 3];
    $cpu = ((int) $fields[14 - 3] + (int) $fields[15 - 3]) / CLK_TCK;

    $sizes = array_fill_keys(array_values(SMAPS_FIELDS), 0);

    foreach (explode("\n", $smaps) as $line) {
        if (preg_match('/^(\w+):\s+(\d+) kB/', $line, $m) && isset(SMAPS_FIELDS[$m[1]])) {
            $sizes[SMAPS_FIELDS[$m[1]]] = (int) $m[2];
        }
    }

    return [
        'pid' => $pid,
        'ppid' => (int) $fields[4 - 3],
        'start_ticks' => $start,
        'age_s' => (int) round($uptime - $start / CLK_TCK),
        'role' => role($cmdline),
        ...$sizes,
        'cpu_s' => round($cpu, 2),
        'cmdline' => $cmdline,
    ];
}

function isPhp(int $pid, string $cmdline): bool
{
    $exe = @readlink("/proc/{$pid}/exe");

    if ($exe !== false) {
        return (bool) preg_match('/^php[\d.]*$/', basename($exe));
    }

    return (bool) preg_match('/^php[\d.]*$/', basename(strtok($cmdline, ' ') ?: ''));
}

/** A queue-prefork stub: `/bin/sh <runtime>/stub-<hash>.sh <worker pid> ...`. */
function isStub(string $cmdline): bool
{
    return (bool) preg_match('#^/bin/sh \S+/stub-[0-9a-f]+\.sh #', $cmdline);
}

function role(string $cmdline): string
{
    if (str_contains($cmdline, 'hp-template') || str_contains($cmdline, 'qp-template')) {
        return 'template';
    }

    if (isStub($cmdline)) {
        return 'stub';
    }

    $tokens = preg_split('/\s+/', $cmdline) ?: [];

    if (in_array('horizon:supervisor', $tokens, true)) {
        return str_contains($cmdline, '[fork-server]') ? 'fork-server' : 'supervisor';
    }

    if (in_array('horizon:work', $tokens, true) || in_array('queue:work', $tokens, true)) {
        return 'worker';
    }

    if (in_array('soak:dispatch', $tokens, true)) {
        return 'dispatcher';
    }

    if (in_array('horizon', $tokens, true)) {
        foreach ($tokens as $token) {
            if (str_starts_with($token, 'horizon:')) {
                return 'other';
            }
        }

        return 'master';
    }

    return 'other';
}

/** @return array<string, int|float|string> */
function cgroup(float $now, array &$state): array
{
    $row = [
        'memory_current_kb' => '', 'anon_kb' => '', 'file_kb' => '', 'shmem_kb' => '',
        'file_mapped_kb' => '', 'kernel_kb' => '', 'cpu_s' => '', 'cpu_pct' => '',
        'swap_kb' => '', 'memory_max_events' => '', 'oom_kills' => '',
    ];

    $current = @file_get_contents('/sys/fs/cgroup/memory.current');

    if ($current !== false) {
        $row['memory_current_kb'] = intdiv((int) trim($current), 1024);
    }

    foreach (explode("\n", (string) @file_get_contents('/sys/fs/cgroup/memory.stat')) as $line) {
        [$key, $value] = array_pad(explode(' ', $line, 2), 2, null);

        if (in_array($key, ['anon', 'file', 'shmem', 'file_mapped', 'kernel'], true)) {
            $row[$key.'_kb'] = intdiv((int) $value, 1024);
        }
    }

    // Swap and limit pressure: a container that swaps or hits memory.max has distorted PSS numbers.
    $swap = @file_get_contents('/sys/fs/cgroup/memory.swap.current');
    $row['swap_kb'] = $swap === false ? '' : intdiv((int) trim($swap), 1024);
    $events = (string) @file_get_contents('/sys/fs/cgroup/memory.events');
    $row['memory_max_events'] = preg_match('/^max (\d+)/m', $events, $m) ? (int) $m[1] : '';
    $row['oom_kills'] = preg_match('/^oom_kill (\d+)/m', $events, $m) ? (int) $m[1] : '';

    if (preg_match('/^usage_usec (\d+)/m', (string) @file_get_contents('/sys/fs/cgroup/cpu.stat'), $m)) {
        $usec = (int) $m[1];
        $row['cpu_s'] = round($usec / 1e6, 1);

        if ($state['cpu_usec'] !== null && $now > $state['cpu_at']) {
            // Percent of one CPU.
            $row['cpu_pct'] = round(($usec - $state['cpu_usec']) / 1e6 / ($now - $state['cpu_at']) * 100, 1);
        }

        $state['cpu_usec'] = $usec;
        $state['cpu_at'] = $now;
    }

    return $row;
}

/** @return array<string, int|float|string> */
function throughput(float $now, array &$state): array
{
    $row = [
        'processed_total' => '', 'failed_total' => '', 'jobs_per_min' => '', 'failed_per_min' => '',
        'runtime_ms_avg' => '', 'pending' => '',
    ];

    if (! class_exists(Redis::class)) {
        return $row;
    }

    try {
        $redis = new Redis;
        $redis->connect(getenv('REDIS_HOST') ?: '127.0.0.1', (int) (getenv('REDIS_PORT') ?: 6379), 2.0);
        $redis->select((int) (getenv('REDIS_DB') ?: 0));
        $prefix = (string) getenv('REDIS_PREFIX');

        $stats = $redis->hGetAll($prefix.'soak:stats') ?: [];
        $pending = 0;

        foreach (QUEUES as $queue) {
            $pending += (int) $redis->lLen($prefix.'queues:'.$queue);
        }

        $redis->close();
    } catch (Throwable $e) {
        fwrite(STDERR, 'sample: redis: '.$e->getMessage()."\n");

        return $row;
    }

    $processed = (int) ($stats['processed'] ?? 0);
    $failed = (int) ($stats['failed'] ?? 0);
    $runtime = 0;

    foreach ($stats as $field => $value) {
        if (str_starts_with($field, 'runtime_ms:')) {
            $runtime += (int) $value;
        }
    }

    $row['processed_total'] = $processed;
    $row['failed_total'] = $failed;
    $row['pending'] = $pending;

    $previous = $state['stats'];

    if ($previous !== null && $now > $state['stats_at'] && $processed >= $previous['processed']) {
        $minutes = ($now - $state['stats_at']) / 60;
        $done = ($processed - $previous['processed']) + ($failed - $previous['failed']);
        $row['jobs_per_min'] = round(($processed - $previous['processed']) / $minutes, 1);
        $row['failed_per_min'] = round(($failed - $previous['failed']) / $minutes, 1);
        $row['runtime_ms_avg'] = $done > 0 ? round(($runtime - $previous['runtime']) / $done, 1) : '';
    }

    $state['stats'] = ['processed' => $processed, 'failed' => $failed, 'runtime' => $runtime];
    $state['stats_at'] = $now;

    return $row;
}

/** @param list<array<string, mixed>> $rows */
function append(string $file, array $columns, array $rows): void
{
    $handle = fopen($file, 'a');

    if ($handle === false) {
        fwrite(STDERR, "sample: cannot open {$file}\n");

        return;
    }

    flock($handle, LOCK_EX);

    if (fstat($handle)['size'] === 0) {
        fputcsv($handle, $columns, ',', '"', '');
    }

    foreach ($rows as $row) {
        fputcsv($handle, array_map(fn ($column) => $row[$column] ?? '', $columns), ',', '"', '');
    }

    flock($handle, LOCK_UN);
    fclose($handle);
}
