<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * Makes a forked process write its stdout a whole line at a time.
 *
 * Stock Horizon gives each worker a pipe of its own, which its supervisor reads and relays. A forked
 * Horizon process writes straight to the stdout it inherited, which every forked process in the tree
 * shares, as shared-log workers share one log file; {@see LineBufferFilter} keeps their lines apart.
 *
 * It filters the STDOUT stream itself, because that is what Symfony's ConsoleOutput writes to outside
 * Windows, so it covers whatever command runs without depending on a console event. STDERR stays as
 * is: after an engine fatal error (a memory or time limit) PHP stops calling user filters, and a
 * filtered stream then drops everything written to it, Laravel's report of that error included.
 */
final class LineBufferedOutput
{
    /** @var array<int, true> stream ids already filtered in this process */
    private static array $filtered = [];

    public static function attachToStdout(): void
    {
        if (\defined('STDOUT')) {
            self::attachTo(\STDOUT);
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
