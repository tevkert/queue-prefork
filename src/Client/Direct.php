<?php

namespace Tevkert\QueuePrefork\Client;

use FFI;
use Tevkert\QueuePrefork\Support\Identity;
use Throwable;

/**
 * Direct, identity-checked signals: used only when a process's reaping server is gone or overdue.
 *
 * - Server alive but overdue: the check also requires the target to still be that server's child,
 *   whose zombie would hold its pid, so the delivery can't hit a reused pid.
 * - Server gone: best effort. The target could be reaped by init and its pid reused between the check
 *   and the kill; pidfds close that gap where FFI is available, and a re-read afterwards reports a
 *   misdirected signal.
 */
final class Direct
{
    private static ?FFI $ffi = null;

    private static ?bool $pidfd = null;

    /** @var list<array{pid: int, signal: int}> misdirections seen, for status/doctor */
    public static array $misdirected = [];

    public static function kill(int $pid, ?string $start, int $signal, ?int $expectedParent = null): bool
    {
        if ($pid <= 0 || ! Identity::alive($pid, $start)) {
            return false;
        }

        if ($expectedParent !== null && Identity::ppid($pid) !== $expectedParent) {
            return false;
        }

        if (self::pidfdAvailable()) {
            return self::pidfdKill($pid, $start, $signal);
        }

        $sent = posix_kill($pid, $signal);
        $now = Identity::startTime($pid);

        if ($now !== null && $start !== null && $now !== $start) {
            self::$misdirected[] = ['pid' => $pid, 'signal' => $signal];
            Report::event('SignalMisdirected', ['pid' => $pid, 'signal' => $signal]);
        }

        return $sent;
    }

    public static function pidfdAvailable(): bool
    {
        if (self::$pidfd !== null) {
            return self::$pidfd;
        }

        if (PHP_OS_FAMILY !== 'Linux' || ! extension_loaded('ffi')) {
            return self::$pidfd = false;
        }

        try {
            self::$ffi = FFI::cdef('long syscall(long number, ...); int close(int fd);', 'libc.so.6');
            $fd = self::$ffi->syscall(self::pidfdOpenNumber(), getmypid(), 0);

            if ($fd < 0) {
                return self::$pidfd = false;
            }

            self::$ffi->close($fd);

            return self::$pidfd = true;
        } catch (Throwable) {
            return self::$pidfd = false;
        }
    }

    private static function pidfdKill(int $pid, ?string $start, int $signal): bool
    {
        $fd = (int) self::$ffi->syscall(self::pidfdOpenNumber(), $pid, 0);

        if ($fd < 0) {
            return false;
        }

        try {
            // The pidfd now pins the process: re-check that it is still the one we meant.
            if (! Identity::alive($pid, $start)) {
                return false;
            }

            return (int) self::$ffi->syscall(self::pidfdSendSignalNumber(), $fd, $signal, null, 0) === 0;
        } finally {
            self::$ffi->close($fd);
        }
    }

    /** Syscall numbers are the same (generic table) on x86_64 and arm64. */
    private static function pidfdOpenNumber(): int
    {
        return 434;
    }

    private static function pidfdSendSignalNumber(): int
    {
        return 424;
    }
}
