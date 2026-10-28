<?php

namespace Tevkert\QueuePrefork\Support;

use Throwable;

/**
 * Unit status files. Dependency-free.
 *
 * The supervisor loop writes an *intent* (provisional: it can still die in shutdown afterwards); the
 * fork server writes the *final* status after reaping the loop. Resolution, once both are gone: the
 * final file, then the template's notification, then StatusUnknown → 137 (stock's result for a killed
 * supervisor). Intents are never trusted alone.
 */
final class StatusFiles
{
    public const UNKNOWN = 137;

    public static function writeIntent(string $statusDir, string $unitId, int $status): bool
    {
        return self::write($statusDir, "{$unitId}.intent", $status);
    }

    public static function writeFinal(string $statusDir, string $unitId, int $status): bool
    {
        return self::write($statusDir, "{$unitId}.final", $status);
    }

    public static function final(string $statusDir, string $unitId): ?int
    {
        return self::read($statusDir, "{$unitId}.final");
    }

    public static function intent(string $statusDir, string $unitId): ?int
    {
        return self::read($statusDir, "{$unitId}.intent");
    }

    /**
     * @return array{status: int, source: string}
     */
    public static function resolve(string $statusDir, string $unitId, ?int $notified): array
    {
        $final = self::final($statusDir, $unitId);

        if ($final !== null) {
            return ['status' => $final, 'source' => 'final'];
        }

        if ($notified !== null) {
            return ['status' => $notified, 'source' => 'template'];
        }

        return ['status' => self::UNKNOWN, 'source' => self::intent($statusDir, $unitId) === null ? 'unknown' : 'intent-only'];
    }

    private static function write(string $statusDir, string $name, int $status): bool
    {
        try {
            RuntimeDir::writeAtomic($statusDir, $name, (string) $status);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function read(string $statusDir, string $name): ?int
    {
        try {
            $raw = RuntimeDir::read($statusDir, $name);
        } catch (Throwable) {
            return null;
        }

        return $raw !== null && preg_match('/^\d+$/', trim($raw)) ? (int) trim($raw) : null;
    }
}
