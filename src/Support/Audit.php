<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * Descriptor and thread audits. Dependency-free.
 *
 * The template holds no app, so anything flagged here was opened by an extension at startup, and
 * forking past it is unsafe: a shared socket gets interleaved writes or a protocol goodbye at a child's
 * exit, and a thread's locks stay locked forever in the child.
 */
final class Audit
{
    /** Extensions whose threads are fork-aware (allowed by the thread audit). */
    private const FORK_AWARE_THREADS = ['ddtrace', 'elastic_apm'];

    /**
     * @param  list<int>  $allowedFds  descriptors the package owns (channels)
     * @param  list<string>  $allowedPeers  `allow_descriptors` patterns (fnmatch), matched against classify()'s
     *                                      description, e.g. `socket socket:[12345]`
     * @return list<string> reasons; empty when clean
     */
    public static function descriptors(array $allowedFds, array $allowedPeers = []): array
    {
        $reasons = [];

        foreach (self::listFds() as $fd) {
            if ($fd <= 2 || in_array($fd, $allowedFds, true)) {
                continue;
            }

            $kind = self::classify($fd);

            if ($kind === null) {
                continue;
            }

            foreach ($allowedPeers as $pattern) {
                if (fnmatch($pattern, $kind)) {
                    continue 2;
                }
            }

            $reasons[] = "fd {$fd}: {$kind}";
        }

        return $reasons;
    }

    /** @return list<string> reasons; empty when single-threaded or only fork-aware threads */
    public static function threads(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [];
        }

        $count = self::threadCount();

        if ($count <= 1) {
            return [];
        }

        $names = [];

        foreach (scandir('/proc/self/task') ?: [] as $tid) {
            if (ctype_digit($tid) && (int) $tid !== getmypid()) {
                $names[] = trim((string) @file_get_contents("/proc/self/task/{$tid}/comm"));
            }
        }

        foreach (self::FORK_AWARE_THREADS as $extension) {
            if (extension_loaded($extension)) {
                return [];
            }
        }

        if (extension_loaded('grpc') && ini_get('grpc.enable_fork_support')) {
            return [];
        }

        return ["{$count} threads: ".implode(', ', $names)];
    }

    public static function threadCount(): int
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return 1;
        }

        $status = (string) @file_get_contents('/proc/self/status');

        return preg_match('/^Threads:\s+(\d+)/m', $status, $m) ? (int) $m[1] : 1;
    }

    /**
     * Descriptors referring to the same open socket as $fd (e.g. fd 3 and the duplicate php://fd/3 makes).
     *
     * @return list<int>
     */
    public static function fdsSharing(int $fd): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return self::listFds(); // macOS audit only warns
        }

        $target = @readlink("/proc/self/fd/{$fd}");

        if ($target === false) {
            return [];
        }

        $same = [];

        foreach (self::listFds() as $candidate) {
            if (@readlink("/proc/self/fd/{$candidate}") === $target) {
                $same[] = $candidate;
            }
        }

        return $same;
    }

    /** @return list<int> */
    public static function listFds(): array
    {
        $dir = PHP_OS_FAMILY === 'Linux' ? '/proc/self/fd' : '/dev/fd';
        $fds = [];

        foreach (@scandir($dir) ?: [] as $entry) {
            if (ctype_digit($entry)) {
                $fds[] = (int) $entry;
            }
        }

        return $fds;
    }

    /**
     * The descriptors still open after the listing (its own directory handle is gone by then). A
     * descriptor spec built from these is safe for proc_open: every index it masks is open, so none of
     * proc_open's own descriptors can take that number and be overwritten in the child.
     *
     * @return list<int>
     */
    public static function openFds(): array
    {
        $dir = PHP_OS_FAMILY === 'Linux' ? '/proc/self/fd' : '/dev/fd';

        return array_values(array_filter(self::listFds(), fn (int $fd) => PHP_OS_FAMILY === 'Linux'
            ? @readlink("{$dir}/{$fd}") !== false
            : @stat("{$dir}/{$fd}") !== false));
    }

    /**
     * A description of an unsafe descriptor, or null when it is safe to inherit.
     */
    public static function classify(int $fd): ?string
    {
        if (PHP_OS_FAMILY === 'Linux') {
            $target = @readlink("/proc/self/fd/{$fd}");

            if ($target === false) {
                return null; // closed while listing (e.g. the listing's own descriptor)
            }

            if (str_starts_with($target, 'socket:')) {
                return "socket {$target}";
            }

            if (str_starts_with($target, 'anon_inode:') && (str_contains($target, 'eventpoll') || str_contains($target, 'eventfd'))) {
                return $target;
            }

            return null;
        }

        // macOS: stat() the fdesc node; never dup and close it (that would drop POSIX locks).
        $stat = @stat("/dev/fd/{$fd}");

        if ($stat === false) {
            return null; // the listing's own descriptor, already closed
        }

        if (($stat['mode'] & 0170000) === 0140000) {
            return 'socket';
        }

        return null;
    }
}
