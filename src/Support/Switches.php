<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * The off switches, read before Laravel boots: QUEUE_PREFORK turns everything off, QUEUE_PREFORK_QUEUE_WORK
 * and QUEUE_PREFORK_HORIZON one mode each, as Laravel resolves them (EarlyConfig: the cached config, else
 * the environment and the env file). The files storage/framework/queue-prefork.off and .off-<mode> do the
 * same for one host, with no config cache in the way. Dependency-free.
 */
final class Switches
{
    /** Each switch's key in config/queue-prefork.php and the variable that key reads. */
    private const KEYS = [
        'all' => ['enabled', 'QUEUE_PREFORK'],
        'queue-work' => ['queue_work', 'QUEUE_PREFORK_QUEUE_WORK'],
        'horizon' => ['horizon', 'QUEUE_PREFORK_HORIZON'],
    ];

    /**
     * @param  string  $mode  queue-work or horizon
     * @return list<string> why the mode is switched off, empty when it isn't
     */
    public static function off(string $release, string $mode): array
    {
        $reasons = [];

        foreach ([self::KEYS['all'], self::KEYS[$mode]] as [$key, $env]) {
            if (self::isOff(EarlyConfig::get($release, $key, $env))) {
                $reasons[] = "{$env} is off (".EarlyConfig::source($release, $key, $env).')';
            }
        }

        foreach (['queue-prefork.off', "queue-prefork.off-{$mode}"] as $file) {
            if (file_exists("{$release}/storage/framework/{$file}")) {
                $reasons[] = "storage/framework/{$file} exists";
            }
        }

        return $reasons;
    }

    /** Off as the config value is after boot: set, and false as a boolean (false, 0, or empty). */
    private static function isOff(mixed $value): bool
    {
        return $value !== null && ! (bool) $value;
    }
}
