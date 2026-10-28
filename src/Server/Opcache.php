<?php

namespace Tevkert\QueuePrefork\Server;

/** opcache state of the template's shared memory. Dependency-free. */
final class Opcache
{
    public static function enabled(): bool
    {
        if (! function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);

        return is_array($status) && ($status['opcache_enabled'] ?? false);
    }

    /**
     * Seconds until the release's bootstrap caches are old enough to be cached: opcache skips files
     * younger than its update protection (2 s), so a template forked right after a deploy would share
     * none of them.
     */
    public static function freshCacheWait(string $release): float
    {
        $newest = 0;

        foreach (glob($release.'/bootstrap/cache/*.php') ?: [] as $file) {
            $newest = max($newest, (int) @filemtime($file));
        }

        return min(2.0, max(0.0, $newest + 2 - microtime(true)));
    }

    public static function report(): array
    {
        if (! self::enabled()) {
            return ['enabled' => false];
        }

        $status = opcache_get_status(false);
        $memory = $status['memory_usage'] ?? [];
        $interned = $status['interned_strings_usage'] ?? [];
        $stats = $status['opcache_statistics'] ?? [];

        return [
            'enabled' => true,
            'used_mb' => round(($memory['used_memory'] ?? 0) / 1048576, 1),
            'free_mb' => round(($memory['free_memory'] ?? 0) / 1048576, 1),
            'interned_used_mb' => round(($interned['used_memory'] ?? 0) / 1048576, 1),
            'interned_free_mb' => round(($interned['free_memory'] ?? 0) / 1048576, 1),
            'cached_scripts' => $stats['num_cached_scripts'] ?? 0,
            'wasted_percent' => round((float) ($memory['current_wasted_percentage'] ?? 0), 1),
            'cache_full' => (bool) ($status['cache_full'] ?? false),
            'restart_pending' => (bool) ($status['restart_pending'] ?? false),
        ];
    }
}
