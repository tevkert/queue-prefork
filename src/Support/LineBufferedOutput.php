<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * Makes a forked process write its output a whole line at a time.
 *
 * Stock Horizon gives each worker a pipe and its supervisor relays what arrives, so lines from
 * different workers never mix. A forked worker writes straight to the stdout it inherited, which every
 * other forked worker shares; {@see LineBufferFilter} keeps their lines apart.
 *
 * It filters the STDOUT and STDERR streams themselves, because that is what Symfony's ConsoleOutput
 * writes to outside Windows, and it needs no console event: Laravel only dispatches CommandStarting
 * once something has opted in to rerouting Symfony's command events.
 */
final class LineBufferedOutput
{
    /** @var array<int, true> stream ids already filtered in this process */
    private static array $filtered = [];

    public static function attachToStdio(): void
    {
        if (\defined('STDOUT')) {
            self::attachTo(\STDOUT);
        }

        if (\defined('STDERR')) {
            self::attachTo(\STDERR);
        }
    }

    /** @param resource $stream */
    public static function attachTo($stream): void
    {
        if (! is_resource($stream) || isset(self::$filtered[(int) $stream])) {
            return;
        }

        if (! in_array(LineBufferFilter::NAME, stream_get_filters(), true)) {
            stream_filter_register(LineBufferFilter::NAME, LineBufferFilter::class);
        }

        if (@stream_filter_append($stream, LineBufferFilter::NAME, STREAM_FILTER_WRITE) !== false) {
            self::$filtered[(int) $stream] = true;
        }
    }
}
