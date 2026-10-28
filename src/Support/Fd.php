<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * Native descriptor numbers for PHP streams, which PHP never exposes. Dependency-free.
 *
 * Shared-log mode runs without FFI, so the stub finds its connection's number this
 * way before handing it to `/bin/sh`.
 */
final class Fd
{
    /**
     * The descriptor behind $stream, matched by inode and file type (and device, where `/proc` gives the
     * real one: Darwin's `/dev/fd` reports its own device and the open mode's permission bits) over this
     * process's fd list; null when there's no match or more than one (a dup'd descriptor is ambiguous).
     *
     * @param  resource  $stream
     */
    public static function number($stream): ?int
    {
        $want = is_resource($stream) ? @fstat($stream) : false;

        if ($want === false) {
            return null;
        }

        $proc = is_dir('/proc/self/fd');
        $dir = $proc ? '/proc/self/fd' : '/dev/fd';
        $found = null;

        foreach (@scandir($dir) ?: [] as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $stat = @stat("{$dir}/{$entry}");

            if ($stat === false || $stat['ino'] !== $want['ino'] || ($stat['mode'] & 0o170000) !== ($want['mode'] & 0o170000)
                || ($proc && $stat['dev'] !== $want['dev'])) {
                continue;
            }

            if ($found !== null) {
                return null;
            }

            $found = (int) $entry;
        }

        return $found;
    }
}
