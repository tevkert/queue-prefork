<?php

namespace Tevkert\QueuePrefork\Support;

use ReflectionObject;
use Throwable;

/**
 * Does the app's default log channel write to stdout or stderr? Shared-log mode
 * would move such logs out of `docker logs` or the journal, so those apps run stock instead.
 *
 * Decided by file identity, not by name: a handler's target is stat()ed (symlinks such as
 * `laravel.log -> /dev/stdout` followed) and compared with the stub's original stdout/stderr and this
 * process's own. Duck-typed over Monolog's handlers (stream, errorlog, and wrappers such as
 * FingersCrossed, Buffer, Group, WhatFailureGroup, Filter, Sampling), so it needs no Monolog import.
 */
final class LogTargets
{
    private const MAX_DEPTH = 8;

    /** @param list<array{0: int, 1: int}> $stubStdio [dev, ino] of the stub's original stdout and stderr */
    public static function defaultChannelWritesToStdio(object $app, array $stubStdio): bool
    {
        try {
            $logger = $app['log']->channel();
            $monolog = method_exists($logger, 'getLogger') ? $logger->getLogger() : $logger;
            $handlers = method_exists($monolog, 'getHandlers') ? $monolog->getHandlers() : [];
        } catch (Throwable) {
            return false;
        }

        return self::anyStdio($handlers, self::identities($stubStdio));
    }

    /**
     * @param  iterable<object>  $handlers
     * @param  list<array{0: int, 1: int}>  $targets
     */
    public static function anyStdio(iterable $handlers, array $targets): bool
    {
        $seen = [];

        foreach ($handlers as $handler) {
            if (is_object($handler) && self::isStdio($handler, $targets, 0, $seen)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The identities that count as stdio: the stub's original fd 1/2, and this process's current fd 1/2
     * (in shared-log mode, the shared log), which is what `/dev/stdout` and `/proc/self/fd/1` resolve to.
     *
     * @param  list<array{0: int, 1: int}>  $stubStdio
     * @return list<array{0: int, 1: int}>
     */
    public static function identities(array $stubStdio): array
    {
        $ids = $stubStdio;

        foreach ([STDOUT, STDERR] as $stream) {
            $stat = @fstat($stream);

            if ($stat !== false) {
                $ids[] = [(int) $stat['dev'], (int) $stat['ino']];
            }
        }

        return array_values(array_filter($ids, fn ($id) => is_array($id) && count($id) === 2 && ($id[1] ?? 0) !== 0));
    }

    /** @param array<int, true> $seen */
    private static function isStdio(object $handler, array $targets, int $depth, array &$seen): bool
    {
        if ($depth > self::MAX_DEPTH || isset($seen[spl_object_id($handler)])) {
            return false;
        }

        $seen[spl_object_id($handler)] = true;

        if (method_exists($handler, 'getUrl') && method_exists($handler, 'getStream')) {
            return self::streamIsStdio($handler, $targets);
        }

        if (str_ends_with(get_class($handler), 'ErrorLogHandler')) {
            return (string) ini_get('error_log') === ''; // the CLI's error_log() then writes to stderr
        }

        if (method_exists($handler, 'getHandler')) {
            try {
                $inner = $handler->getHandler();
            } catch (Throwable) {
                $inner = null;
            }

            if (is_object($inner) && self::isStdio($inner, $targets, $depth + 1, $seen)) {
                return true;
            }
        }

        foreach (self::wrapped($handler) as $inner) {
            if (self::isStdio($inner, $targets, $depth + 1, $seen)) {
                return true;
            }
        }

        return false;
    }

    private static function streamIsStdio(object $handler, array $targets): bool
    {
        try {
            $stream = $handler->getStream();
            $url = $handler->getUrl();
        } catch (Throwable) {
            return false;
        }

        if (is_resource($stream)) {
            $stat = @fstat($stream);

            return $stat !== false && self::matches((int) $stat['dev'], (int) $stat['ino'], $targets);
        }

        if (! is_string($url) || $url === '') {
            return false;
        }

        if (preg_match('#^php://(stdout|stderr|output|fd/[12])$#i', $url)) {
            return true;
        }

        $stat = @stat($url);

        return $stat !== false && self::matches((int) $stat['dev'], (int) $stat['ino'], $targets);
    }

    private static function matches(int $dev, int $ino, array $targets): bool
    {
        foreach ($targets as [$tDev, $tIno]) {
            if ($dev === (int) $tDev && $ino === (int) $tIno) {
                return true;
            }
        }

        return false;
    }

    /** @return list<object> handlers a wrapper keeps in its `handler` or `handlers` property */
    private static function wrapped(object $handler): array
    {
        $found = [];
        $reflection = new ReflectionObject($handler);

        do {
            foreach (['handler', 'handlers'] as $name) {
                if (! $reflection->hasProperty($name)) {
                    continue;
                }

                $property = $reflection->getProperty($name);
                $value = $property->isInitialized($handler) ? $property->getValue($handler) : null;

                foreach (is_array($value) ? $value : [$value] as $inner) {
                    if (is_object($inner) && ! $inner instanceof \Closure) {
                        $found[] = $inner;
                    }
                }
            }
        } while ($found === [] && ($reflection = $reflection->getParentClass()) !== false);

        return $found;
    }
}
