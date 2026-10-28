<?php

namespace Tevkert\QueuePrefork\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\MasterSupervisor;
use Tevkert\QueuePrefork\Settings;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\WorkTemplates;
use Throwable;

/**
 * Mode, templates and per-process memory of this app's Horizon and `queue:work` workers. Exits non-zero
 * while anything is degraded: Horizon running stock, a `queue:work` key held stock by a marker,
 * or nothing running at all.
 */
class StatusCommand extends Command
{
    protected $signature = 'queue-prefork:status {--json : Machine-readable output}';

    protected $description = 'Show the queue-prefork mode, templates and memory of this host';

    public function handle(): int
    {
        $snapshot = self::horizonSnapshot();
        $release = realpath(base_path()) ?: base_path();
        $templates = WorkTemplates::list($release);
        $markers = WorkTemplates::markers($release);
        $processes = self::processes(base_path());
        $horizonDegraded = $snapshot !== null && (($snapshot['mode'] ?? 'stock') !== 'prefork'
            || collect($snapshot['units'] ?? [])->contains(fn ($unit) => ($unit['kind'] ?? '') === 'stock'));
        $result = [
            'enabled' => Settings::enabled($this->laravel),
            'source' => Settings::switchSource($this->laravel),
            'snapshot' => $snapshot,
            'templates' => $templates,
            'markers' => $markers,
            'processes' => $processes,
            'total_pss_mb' => round(array_sum(array_column($processes, 'pss_mb')), 1),
            'degraded' => $horizonDegraded || $markers !== [] || ($snapshot === null && $templates === []),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['degraded'] ? 1 : 0;
        }

        $this->components->twoColumnDetail('Enabled', ($result['enabled'] ? 'yes' : 'no').' (from '.$result['source'].')');

        if ($snapshot !== null) {
            $reason = isset($snapshot['reason']) ? ' — '.$snapshot['reason'] : '';
            $hosts = ($snapshot['template']['hosted'] ?? false) ? ' (hosts the master)' : '';
            $template = ', template '.($snapshot['template']['pid'] ?? '—').$hosts;
            $this->components->twoColumnDetail('Horizon', ($snapshot['mode'] ?? 'unknown').$reason.$template);
        }

        foreach ($templates as $template) {
            $opcache = $template['opcache'] ?? [];
            $this->components->twoColumnDetail(
                "queue:work template {$template['pid']}",
                "{$template['workers']} workers, {$template['output']} mode"
                    .(($opcache['enabled'] ?? false) ? ", opcache {$opcache['used_mb']} MB, {$opcache['wasted_percent']} % wasted" : '')
                    .(($template['retiring'] ?? false) ? ', retiring' : ''),
            );
        }

        foreach ($markers as $marker) {
            $this->components->warn('queue:work runs stock until '.date('H:i:s', $marker['until'])." (key {$marker['key']}): {$marker['reason']}");
        }

        if ($snapshot === null && $templates === []) {
            $where = implode(' or ', RuntimeDir::sharedCandidates());
            $this->components->warn("Nothing running: no Horizon snapshot, and no queue:work template for this app in {$where} (run this as the workers' user).");
        }

        $this->table(
            ['pid', 'ppid', 'role', 'rss MB', 'pss MB', 'private MB'],
            array_map(fn ($p) => [$p['pid'], $p['ppid'], $p['role'], $p['rss_mb'], $p['pss_mb'], $p['private_mb']], $processes),
        );
        $this->components->twoColumnDetail('Total PSS', $result['total_pss_mb'].' MB');

        return $result['degraded'] ? 1 : 0;
    }

    /** Horizon's prefork snapshot, or null without Horizon or before its first start. */
    private static function horizonSnapshot(): ?array
    {
        if (! class_exists(MasterSupervisor::class)) {
            return null;
        }

        try {
            $raw = Redis::connection('horizon')->get('prefork:snapshot:'.MasterSupervisor::basename());
        } catch (Throwable) {
            return null;
        }

        return $raw ? json_decode($raw, true) : null;
    }

    /**
     * This site's worker processes, Horizon's and `queue:work`'s: on a host with several sites, only
     * those working in $basePath. Stubs are shell scripts, so their rows are the only non-PHP ones.
     *
     * @return list<array<string, mixed>>
     */
    public static function processes(?string $basePath = null): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }

        $rows = [];

        foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
            $role = self::roleOf(str_replace("\0", ' ', (string) @file_get_contents($dir.'/cmdline')));

            if ($role === null) {
                continue;
            }

            $cwd = @readlink($dir.'/cwd');

            if ($basePath !== null && ($cwd === false || ($cwd !== $basePath && ! str_starts_with($cwd, rtrim($basePath, '/').'/')))) {
                continue;
            }

            $smaps = (string) @file_get_contents($dir.'/smaps_rollup');
            $kb = fn (string $key) => preg_match("/^{$key}:\s+(\d+)/m", $smaps, $m) ? (int) $m[1] : 0;
            $stat = (string) @file_get_contents($dir.'/stat');
            $after = substr($stat, (int) strrpos($stat, ')') + 2);
            $rows[] = [
                'pid' => (int) basename($dir),
                'ppid' => (int) (explode(' ', $after)[1] ?? 0),
                'role' => $role,
                'rss_mb' => round($kb('Rss') / 1024, 1),
                'pss_mb' => round($kb('Pss') / 1024, 1),
                'private_mb' => round(($kb('Private_Clean') + $kb('Private_Dirty')) / 1024, 1),
            ];
        }

        return $rows;
    }

    /**
     * A process's role in a worker tree, prefork or stock, from its command line: a template or stub by
     * its own title or script, the rest as `php [options] <entry> <command>`. Null for anything else,
     * such as tinker, `horizon:status` or the scheduler's `horizon:snapshot`.
     */
    public static function roleOf(string $cmd): ?string
    {
        if (preg_match('#^\S*php\S* \[[hq]p-template:#', $cmd)) {
            return 'template';
        }

        if (preg_match('#^/bin/sh \S+/stub-[0-9a-f]+\.sh #', $cmd)) {
            return 'stub';
        }

        if (! preg_match('#^\S*php\S*(?: -[dc] ?\S+| -[a-zA-Z]+)* \S+ (horizon|horizon:supervisor|horizon:work|queue:work)(?: |$)#', $cmd, $m)) {
            return null;
        }

        return match ($m[1]) {
            'horizon' => 'master',
            'horizon:supervisor' => str_contains($cmd, '[fork-server]') ? 'fork-server' : 'supervisor',
            default => 'worker',
        };
    }
}
