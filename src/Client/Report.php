<?php

namespace Tevkert\QueuePrefork\Client;

use Throwable;

/** Loud reporting of prefork events: error log plus a Laravel event when an app exists. */
final class Report
{
    public static function event(string $name, array $context = [], string $level = 'error'): void
    {
        $line = "queue-prefork: {$name} ".json_encode($context, JSON_UNESCAPED_SLASHES);

        try {
            if (function_exists('app') && app()->bound('log')) {
                app('log')->log($level, $line, $context);
            } else {
                error_log($line);
            }

            if (function_exists('event') && app()->bound('events')) {
                event('queue-prefork.'.$name, [$context]);
            }
        } catch (Throwable) {
            error_log($line);
        }
    }
}
