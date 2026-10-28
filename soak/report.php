<?php

/*
 * Summarises soak results into soak/results/report.md.
 *
 * Reads <results>/<mode>/processes.csv and cgroup.csv (written by soak/sample.php) for every mode
 * directory found (stock and prefork, plus any variant such as stock-split), and reports:
 *   - total PSS per role and for the whole worker tree (Horizon or queue:work): median, p95 and last-hour mean over samples;
 *   - per-process PSS and private memory by role;
 *   - private memory growth per worker over its lifetime (by age bucket, and MB/h per worker);
 *   - container memory (cgroup) and CPU;
 *   - throughput (jobs/min), failures and mean runtime;
 *   - prefork against stock: the design's soak pass criteria: total PSS <= 55 % of stock,
 *     throughput within +-5 %.
 *
 * Usage: php soak/report.php [--results=<dir>] [--out=<file>] [--modes=stock,prefork]
 * Plain PHP, no dependencies.
 */

const TREE_ROLES = ['master', 'template', 'fork-server', 'supervisor', 'worker', 'stub'];
const ROLE_ORDER = ['master', 'template', 'fork-server', 'supervisor', 'worker', 'stub', 'dispatcher', 'other'];
const AGE_BUCKETS = [[0, 5], [5, 15], [15, 30], [30, 45], [45, 60], [60, PHP_INT_MAX]];

$options = getopt('', ['results:', 'out:', 'modes:']);
$results = rtrim((string) ($options['results'] ?? __DIR__.'/results'), '/');
$out = (string) ($options['out'] ?? $results.'/report.md');

$modes = isset($options['modes'])
    ? array_filter(explode(',', (string) $options['modes']))
    : array_values(array_map('basename', array_map('dirname', glob($results.'/*/processes.csv') ?: [])));

usort($modes, fn ($a, $b) => [modeRank($a), $a] <=> [modeRank($b), $b]);

if ($modes === []) {
    fwrite(STDERR, "report: no <mode>/processes.csv under {$results}\n");
    exit(1);
}

$data = [];

foreach ($modes as $mode) {
    $data[$mode] = analyse(
        readCsv("{$results}/{$mode}/processes.csv"),
        readCsv("{$results}/{$mode}/cgroup.csv"),
    );
}

$md = render($data);
file_put_contents($out, $md);
fwrite(STDOUT, $md);
fwrite(STDERR, "report: wrote {$out}\n");

function modeRank(string $mode): int
{
    return str_starts_with($mode, 'stock') ? 0 : (str_starts_with($mode, 'prefork') ? 1 : 2);
}

/** @return list<array<string, string>> */
function readCsv(string $file): array
{
    if (! is_file($file) || ($handle = fopen($file, 'r')) === false) {
        return [];
    }

    $header = fgetcsv($handle, null, ',', '"', '');
    $rows = [];

    while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
        if ($header === false || count($line) !== count($header)) {
            continue;
        }

        $rows[] = array_combine($header, $line);
    }

    fclose($handle);

    return $rows;
}

function analyse(array $processes, array $cgroup): array
{
    // Per sample: totals by role.
    $samples = [];
    $roles = [];

    foreach ($processes as $row) {
        $t = (int) $row['unix'];
        $role = $row['role'];
        $roles[$role] = true;
        $samples[$t][$role]['pss'] = ($samples[$t][$role]['pss'] ?? 0) + (int) $row['pss_kb'];
        $samples[$t][$role]['private'] = ($samples[$t][$role]['private'] ?? 0) + (int) $row['private_clean_kb'] + (int) $row['private_dirty_kb'];
        $samples[$t][$role]['rss'] = ($samples[$t][$role]['rss'] ?? 0) + (int) $row['rss_kb'];
        $samples[$t][$role]['count'] = ($samples[$t][$role]['count'] ?? 0) + 1;
    }

    ksort($samples);
    $times = array_keys($samples);
    $last = $times === [] ? 0 : end($times);
    $roles = array_values(array_filter(ROLE_ORDER, fn ($role) => isset($roles[$role])));

    $series = [];

    foreach ($samples as $t => $byRole) {
        $tree = ['pss' => 0, 'private' => 0, 'rss' => 0, 'count' => 0];

        foreach ($roles as $role) {
            foreach (['pss', 'private', 'rss', 'count'] as $metric) {
                $value = $byRole[$role][$metric] ?? 0;
                $series[$role][$metric][$t] = $value;

                if (in_array($role, TREE_ROLES, true)) {
                    $tree[$metric] += $value;
                }
            }
        }

        foreach ($tree as $metric => $value) {
            $series['tree total'][$metric][$t] = $value;
        }
    }

    // Per process: PSS and private by role, over every process row.
    $perProcess = [];

    foreach ($processes as $row) {
        $perProcess[$row['role']]['pss'][] = (int) $row['pss_kb'];
        $perProcess[$row['role']]['private'][] = (int) $row['private_clean_kb'] + (int) $row['private_dirty_kb'];
        $perProcess[$row['role']]['shared'][] = (int) $row['shared_clean_kb'] + (int) $row['shared_dirty_kb'];
    }

    return [
        'samples' => count($samples),
        'first' => $times === [] ? 0 : reset($times),
        'last' => $last,
        'roles' => $roles,
        'series' => $series,
        'perProcess' => $perProcess,
        'workers' => workerGrowth($processes),
        'cgroup' => cgroupStats($cgroup, $last),
    ];
}

function workerGrowth(array $processes): array
{
    $instances = [];
    $byAge = array_fill(0, count(AGE_BUCKETS), []);

    foreach ($processes as $row) {
        if ($row['role'] !== 'worker') {
            continue;
        }

        $private = (int) $row['private_clean_kb'] + (int) $row['private_dirty_kb'];
        $age = (int) $row['age_s'] / 60;
        $instances[$row['pid'].':'.$row['start_ticks']][] = ['t' => (int) $row['unix'], 'private' => $private, 'age' => $age];

        foreach (AGE_BUCKETS as $i => [$from, $to]) {
            if ($age >= $from && $age < $to) {
                $byAge[$i][] = $private;
                break;
            }
        }
    }

    $first = $lastPrivate = $rates = $lifetimes = [];

    foreach ($instances as $points) {
        usort($points, fn ($a, $b) => $a['t'] <=> $b['t']);
        $a = $points[0];
        $b = end($points);
        $first[] = $a['private'];
        $lastPrivate[] = $b['private'];
        $lifetimes[] = $b['age'];

        if ($b['t'] - $a['t'] >= 600) {
            $rates[] = ($b['private'] - $a['private']) / 1024 / (($b['t'] - $a['t']) / 3600);
        }
    }

    return [
        'instances' => count($instances),
        'first' => $first,
        'last' => $lastPrivate,
        'lifetimes' => $lifetimes,
        'rates' => $rates,
        'byAge' => $byAge,
    ];
}

function cgroupStats(array $rows, int $last): array
{
    $col = fn (string $name) => array_values(array_map('floatval', array_filter(array_column($rows, $name), fn ($v) => $v !== '')));
    $lastHour = array_values(array_filter($rows, fn ($row) => (int) $row['unix'] > $last - 3600));
    $lastHourCol = fn (string $name) => array_values(array_map('floatval', array_filter(array_column($lastHour, $name), fn ($v) => $v !== '')));

    $processed = $col('processed_total');
    $failed = $col('failed_total');
    $times = array_map('intval', array_column(array_filter($rows, fn ($row) => $row['processed_total'] !== ''), 'unix'));
    $minutes = count($times) > 1 ? (end($times) - reset($times)) / 60 : 0;

    // Mean runtime weighted by jobs: sum(avg * jobs) / sum(jobs) over rows with both.
    $weighted = $jobs = 0.0;

    foreach ($rows as $row) {
        if ($row['runtime_ms_avg'] !== '' && $row['jobs_per_min'] !== '') {
            $weighted += (float) $row['runtime_ms_avg'] * (float) $row['jobs_per_min'];
            $jobs += (float) $row['jobs_per_min'];
        }
    }

    // Jobs/min over the active window only: rows after the first processed job and up to the last
    // row whose counter still moved, so idle tails don't dilute the rate.
    $active = array_values(array_filter($rows, fn ($row) => $row['jobs_per_min'] !== '' && (float) $row['jobs_per_min'] > 0));

    return [
        'memory' => $col('memory_current_kb'),
        'memory_last_hour' => $lastHourCol('memory_current_kb'),
        'anon' => $col('anon_kb'),
        'file' => $col('file_kb'),
        'shmem' => $col('shmem_kb'),
        'cpu' => $col('cpu_pct'),
        'jobs_per_min' => array_map(fn ($row) => (float) $row['jobs_per_min'], $active),
        'processed' => count($processed) > 1 ? end($processed) - reset($processed) : 0,
        'failed' => count($failed) > 1 ? end($failed) - reset($failed) : 0,
        'minutes' => $minutes,
        'runtime_ms' => $jobs > 0 ? $weighted / $jobs : null,
        'pending' => $col('pending'),
        'swap' => $col('swap_kb'),
        'memory_max_events' => $col('memory_max_events'),
        'oom_kills' => $col('oom_kills'),
    ];
}

function percentile(array $values, float $p): ?float
{
    if ($values === []) {
        return null;
    }

    sort($values);
    $rank = (int) ceil($p / 100 * count($values));

    return (float) $values[max(0, min(count($values) - 1, $rank - 1))];
}

function median(array $values): ?float
{
    if ($values === []) {
        return null;
    }

    sort($values);
    $n = count($values);

    return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

function mean(array $values): ?float
{
    return $values === [] ? null : array_sum($values) / count($values);
}

/** Mean of a time series (t => value) over its last hour. */
function lastHour(array $series, int $last): ?float
{
    return mean(array_values(array_filter($series, fn ($t) => $t > $last - 3600, ARRAY_FILTER_USE_KEY)));
}

function mb(?float $kb, int $decimals = 1): string
{
    return $kb === null ? '–' : number_format($kb / 1024, $decimals);
}

function num(?float $value, int $decimals = 1): string
{
    return $value === null ? '–' : number_format($value, $decimals);
}

function table(array $header, array $rows): string
{
    $out = '| '.implode(' | ', $header)." |\n";
    $out .= '|'.implode('|', array_map(fn ($i) => $i === 0 ? '---' : '---:', array_keys($header)))."|\n";

    foreach ($rows as $row) {
        $out .= '| '.implode(' | ', $row)." |\n";
    }

    return $out."\n";
}

function render(array $data): string
{
    $md = "# Soak report\n\n";
    $md .= 'Generated '.gmdate('Y-m-d H:i').' UTC from `soak/results/<mode>/{processes,cgroup}.csv`. ';
    $md .= "Memory is in MB (1024²); PSS splits shared pages between the processes that map them, so per-role PSS adds up to the tree's real footprint. ";
    $md .= '"Tree total" is master + template + fork-server + supervisor + worker + stub, whichever run (the dispatcher and the sampler are left out). ';
    $md .= "Last hour = mean over the samples of the run's final 60 minutes.\n\n";

    $md .= "## Runs\n\n";
    $rows = [];

    foreach ($data as $mode => $d) {
        $rows[] = [
            $mode,
            $d['samples'],
            $d['first'] ? gmdate('Y-m-d H:i', $d['first']) : '–',
            $d['last'] ? gmdate('Y-m-d H:i', $d['last']) : '–',
            num(($d['last'] - $d['first']) / 3600, 2),
        ];
    }

    $md .= table(['mode', 'samples', 'first (UTC)', 'last (UTC)', 'hours'], $rows);

    // One verdict per stock/prefork pair: stock + prefork, stock-uncached + prefork-uncached, ...
    foreach (array_keys($data) as $mode) {
        $pair = str_starts_with($mode, 'stock') ? 'prefork'.substr($mode, 5) : null;

        if ($pair !== null && isset($data[$pair])) {
            $md .= verdict($data[$mode], $data[$pair], $mode, $pair);
        }
    }

    $md .= "## Total PSS by role (MB)\n\n";
    $rows = [];

    foreach ($data as $mode => $d) {
        foreach ([...$d['roles'], 'tree total'] as $role) {
            $pss = $d['series'][$role]['pss'] ?? [];
            $count = $d['series'][$role]['count'] ?? [];
            $label = $role === 'tree total' ? '**tree total**' : $role;
            $rows[] = [
                $mode, $label, num(median(array_values($count)), 1),
                mb(median(array_values($pss))), mb(percentile(array_values($pss), 95)), mb(lastHour($pss, $d['last'])),
            ];
        }
    }

    $md .= table(['mode', 'role', 'processes (median)', 'PSS median', 'PSS p95', 'PSS last hour'], $rows);

    $md .= "## Per process (MB, over every sampled process)\n\n";
    $rows = [];

    foreach ($data as $mode => $d) {
        foreach (ROLE_ORDER as $role) {
            if (! isset($d['perProcess'][$role])) {
                continue;
            }

            $p = $d['perProcess'][$role];
            $rows[] = [
                $mode, $role, count($p['pss']),
                mb(median($p['pss'])), mb(percentile($p['pss'], 95)),
                mb(median($p['private'])), mb(percentile($p['private'], 95)), mb(median($p['shared'])),
            ];
        }
    }

    $md .= table(['mode', 'role', 'rows', 'PSS median', 'PSS p95', 'private median', 'private p95', 'shared median'], $rows);

    $md .= "## Worker private memory over a worker's life\n\n";
    $md .= "Private = Private_Clean + Private_Dirty. By age bucket: the median over worker samples of that age. ";
    $md .= "Growth: (last − first sample) per worker that was sampled for at least 10 minutes.\n\n";
    $header = ['mode', 'workers seen', 'life median (min)'];

    foreach (AGE_BUCKETS as [$from, $to]) {
        $header[] = $to === PHP_INT_MAX ? "≥{$from} min" : "{$from}–{$to} min";
    }

    $header = [...$header, 'growth median (MB/h)', 'growth p95 (MB/h)', 'growth max (MB/h)'];
    $rows = [];

    foreach ($data as $mode => $d) {
        $w = $d['workers'];
        $row = [$mode, $w['instances'], num(median($w['lifetimes']), 0)];

        foreach ($w['byAge'] as $values) {
            $row[] = $values === [] ? '–' : mb(median($values)).' ('.count($values).')';
        }

        $rates = $w['rates'];
        $rows[] = [...$row, num(median($rates)), num(percentile($rates, 95)), num($rates === [] ? null : max($rates))];
    }

    $md .= table($header, $rows);

    $md .= "## Container (cgroup) and throughput\n\n";
    $rows = [];

    foreach ($data as $mode => $d) {
        $c = $d['cgroup'];
        $rows[] = [
            $mode,
            mb(median($c['memory'])), mb(percentile($c['memory'], 95)), mb(mean($c['memory_last_hour'])),
            mb(median($c['anon'])), mb(median($c['file'])), mb(median($c['shmem'])),
            num(median($c['cpu'])).' / '.num(mean($c['cpu'])),
            $c['swap'] === [] ? '–' : mb(max($c['swap'])),
            $c['memory_max_events'] === [] ? '–' : (string) (int) (max($c['memory_max_events']) - min($c['memory_max_events'])),
            $c['oom_kills'] === [] ? '–' : (string) (int) (max($c['oom_kills']) - min($c['oom_kills'])),
        ];
    }

    $md .= table(['mode', 'memory.current median', 'p95', 'last hour', 'anon median', 'file median', 'shmem median', 'CPU % of one core (median / mean)', 'swap max', 'memory.max hits', 'OOM kills'], $rows);
    $md .= "Swap or memory.max hits mean the container was under its limit's pressure and its PSS numbers are understated.\n\n";

    $rows = [];

    foreach ($data as $mode => $d) {
        $c = $d['cgroup'];
        $rows[] = [
            $mode, $c['processed'], $c['failed'],
            num(median($c['jobs_per_min'])), num($c['minutes'] > 0 ? $c['processed'] / $c['minutes'] : null),
            num($c['runtime_ms']), num(median($c['pending']), 0).' / '.num(percentile($c['pending'], 95), 0),
        ];
    }

    $md .= table(['mode', 'jobs processed', 'jobs failed', 'jobs/min (median, active minutes)', 'jobs/min (whole run)', 'mean runtime (ms)', 'pending (median / p95)'], $rows);

    $md .= "Not sampled here: opcache fill and cached-script checks (the package's status command reports those), job runtimes per class.\n";

    return $md;
}

function verdict(array $stock, array $prefork, string $stockName = 'stock', string $preforkName = 'prefork'): string
{
    $s = $stock['series']['tree total']['pss'] ?? [];
    $p = $prefork['series']['tree total']['pss'] ?? [];
    $sMedian = median(array_values($s));
    $pMedian = median(array_values($p));
    $sHour = lastHour($s, $stock['last']);
    $pHour = lastHour($p, $prefork['last']);
    $ratio = $sMedian ? $pMedian / $sMedian : null;
    $hourRatio = $sHour ? $pHour / $sHour : null;

    $sRate = $stock['cgroup']['minutes'] > 0 ? $stock['cgroup']['processed'] / $stock['cgroup']['minutes'] : null;
    $pRate = $prefork['cgroup']['minutes'] > 0 ? $prefork['cgroup']['processed'] / $prefork['cgroup']['minutes'] : null;
    $rateDelta = $sRate ? ($pRate - $sRate) / $sRate : null;

    $pass = fn (?bool $ok) => $ok === null ? 'n/a' : ($ok ? 'PASS' : 'FAIL');

    $md = "## {$preforkName} against {$stockName}\n\n";
    $md .= table(['criterion', $stockName, $preforkName, "{$preforkName} / {$stockName}", 'target', 'result'], [
        ['Tree total PSS, median (MB)', mb($sMedian), mb($pMedian), $ratio === null ? '–' : num($ratio * 100).' %', '≤ 55 %', $pass($ratio === null ? null : $ratio <= 0.55)],
        ['Tree total PSS, last hour (MB)', mb($sHour), mb($pHour), $hourRatio === null ? '–' : num($hourRatio * 100).' %', '≤ 55 %', $pass($hourRatio === null ? null : $hourRatio <= 0.55)],
        ['Throughput, jobs/min (whole run)', num($sRate), num($pRate), $rateDelta === null ? '–' : sprintf('%+.1f %%', $rateDelta * 100), '±5 %', $pass($rateDelta === null ? null : abs($rateDelta) <= 0.05)],
        ['Jobs failed', (string) $stock['cgroup']['failed'], (string) $prefork['cgroup']['failed'], '', 'equal', $pass($stock['cgroup']['failed'] === $prefork['cgroup']['failed'])],
    ]);

    $md .= "Fork-safety errors are not visible in these CSVs: check the containers' logs (`docker compose logs app-prefork`) and `storage/logs/laravel.log` in each container.\n\n";

    return $md;
}
