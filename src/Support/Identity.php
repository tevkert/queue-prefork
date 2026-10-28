<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * Process identity: (pid, start time). Dependency-free.
 *
 * A pid alone can be reused after its process is reaped; the start time tells a reused pid apart.
 * Linux reads /proc/<pid>/stat, parsed after the last ")" because the command name may contain
 * spaces and parentheses. macOS (development only) asks ps once per call.
 */
final class Identity
{
    /** @var array<int, array{state: string, ppid: int, start: string}|null> */
    private static array $macCache = [];

    private static float $macCacheAt = 0.0;

    /** @return array{state: string, ppid: int, start: string}|null */
    public static function stat(int $pid): ?array
    {
        if ($pid <= 0) {
            return null;
        }

        if (PHP_OS_FAMILY === 'Linux') {
            $raw = @file_get_contents("/proc/{$pid}/stat");

            return $raw === false ? null : self::parseLinuxStat($raw);
        }

        return self::macStat($pid);
    }

    /** @return array{state: string, ppid: int, start: string}|null */
    public static function parseLinuxStat(string $raw): ?array
    {
        $close = strrpos($raw, ')');

        if ($close === false) {
            return null;
        }

        // Fields after the command name start at field 3 (state); field n is at index n - 3.
        $fields = explode(' ', trim(substr($raw, $close + 2)));

        if (count($fields) < 20) {
            return null;
        }

        return ['state' => $fields[0], 'ppid' => (int) $fields[1], 'start' => $fields[19]];
    }

    public static function startTime(int $pid): ?string
    {
        return self::stat($pid)['start'] ?? null;
    }

    public static function ppid(int $pid): ?int
    {
        return self::stat($pid)['ppid'] ?? null;
    }

    /** True when the pid still belongs to the process that had this start time, and it hasn't exited. */
    public static function alive(int $pid, ?string $start): bool
    {
        $stat = self::stat($pid);

        if ($stat === null) {
            return false;
        }

        if ($start !== null && $stat['start'] !== $start) {
            return false;
        }

        return ! in_array($stat['state'], ['Z', 'X', 'x'], true);
    }

    /** "<pid>-<start>", used to name status files. */
    public static function key(int $pid, ?string $start): string
    {
        return $pid.'-'.preg_replace('/[^0-9A-Za-z]+/', '', (string) $start);
    }

    public static function forgetCache(): void
    {
        self::$macCache = [];
    }

    /** @return array{state: string, ppid: int, start: string}|null */
    private static function macStat(int $pid): ?array
    {
        if (microtime(true) - self::$macCacheAt > 0.5) {
            self::$macCache = [];
            self::$macCacheAt = microtime(true);
        }

        if (array_key_exists($pid, self::$macCache)) {
            return self::$macCache[$pid];
        }

        $out = @shell_exec('LC_ALL=C TZ=UTC ps -o stat=,ppid=,lstart= -p '.(int) $pid.' 2>/dev/null');
        $line = trim((string) $out);

        if ($line === '' || ! preg_match('/^(\S+)\s+(\d+)\s+(.+)$/', $line, $m)) {
            return self::$macCache[$pid] = null;
        }

        return self::$macCache[$pid] = ['state' => $m[1][0], 'ppid' => (int) $m[2], 'start' => trim($m[3])];
    }
}
