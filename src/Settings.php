<?php

namespace Tevkert\QueuePrefork;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\MasterSupervisor;
use Throwable;

/** Package settings and the runtime switch. */
final class Settings
{
    /** Entry scripts with a fixed provider list never merged the package config: do it here. */
    public static function mergeDefaults(Application $app): void
    {
        $config = $app['config'];
        $defaults = require dirname(__DIR__).'/config/queue-prefork.php';
        $config->set('queue-prefork', array_replace_recursive($defaults, (array) $config->get('queue-prefork', [])));
    }

    /**
     * The runtime switch (Redis) wins over config, so a rollback works under a cached config. A mode
     * (queue-work or horizon) is off when the package or the mode is switched off.
     */
    public static function enabled(Application $app, ?string $mode = null): bool
    {
        $switch = self::switchValue($mode);

        if ($switch !== null) {
            return $switch === 'enable';
        }

        $config = $app['config'];

        return (bool) $config->get('queue-prefork.enabled')
            && ($mode === null || (bool) $config->get('queue-prefork.'.str_replace('-', '_', $mode), true));
    }

    public static function switchSource(Application $app, ?string $mode = null): string
    {
        return self::switchValue($mode) !== null ? 'switch' : 'config';
    }

    /**
     * enable, disable or null. For the package and for the mode, this host's switch wins over --all's;
     * then a disable wins over an enable.
     */
    public static function switchValue(?string $mode = null): ?string
    {
        if (! class_exists(MasterSupervisor::class)) {
            return null; // the switch lives on Horizon's Redis connection: queue:work-only apps use config
        }

        try {
            $redis = Redis::connection('horizon');
            $values = [];

            foreach ($mode === null ? [''] : ['', ":{$mode}"] as $suffix) {
                $value = $redis->get(self::switchKey(false, $suffix)) ?: $redis->get(self::switchKey(true, $suffix));
                $values[] = in_array($value, ['enable', 'disable'], true) ? $value : null;
            }

            return in_array('disable', $values, true) ? 'disable' : (in_array('enable', $values, true) ? 'enable' : null);
        } catch (Throwable) {
            return null;
        }
    }

    /** Sets or clears the switch; clearing the package's switch clears the modes' too. */
    public static function setSwitch(?string $value, bool $allHosts, ?string $mode = null): void
    {
        $redis = Redis::connection('horizon');

        if ($value !== null) {
            $redis->set(self::switchKey($allHosts, $mode === null ? '' : ":{$mode}"), $value);

            return;
        }

        foreach ($mode === null ? ['', ':queue-work', ':horizon'] : [":{$mode}"] as $suffix) {
            $redis->del(self::switchKey($allHosts, $suffix));
        }
    }

    private static function switchKey(bool $allHosts, string $suffix): string
    {
        return 'prefork:switch:'.($allHosts ? '*' : MasterSupervisor::basename()).$suffix;
    }
}
