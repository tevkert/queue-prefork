<?php

namespace Tevkert\QueuePrefork;

use Tevkert\QueuePrefork\Client\Broker;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Client\Report;
use Tevkert\QueuePrefork\Horizon\PreforkSupervisor;
use Tevkert\QueuePrefork\Server\RoleGuard;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Throwable;

/**
 * The supervisor loop's link to its fork server, and the unit's crash breaker.
 */
final class Link
{
    private static ?Broker $broker = null;

    public static ?PreforkSupervisor $supervisor = null;

    /** @var list<float> */
    private static array $earlyExits = [];

    private static bool $ending = false;

    public static function broker(): Broker
    {
        if (self::$broker === null) {
            $broker = new Broker(Runtime::$channel, 'w', (float) Runtime::config('spawn_timeout', 10), 30);
            $broker->onLost = fn () => self::endUnit('fork server lost');
            $broker->onOverdue = fn () => self::endUnit('fork server overdue');
            self::$broker = $broker;
        }

        return self::$broker;
    }

    public static function hello(): void
    {
        self::broker()->send(['t' => 'hello']);
        self::broker()->drain(1.0);
    }

    public static function armed(): void
    {
        self::broker()->send(['t' => 'armed']);
        self::broker()->drain(1.0);
    }

    public static function tick(): void
    {
        self::broker()->pump();
    }

    public static function endUnit(string $reason): void
    {
        if (self::$ending) {
            return;
        }

        self::$ending = true;

        if (self::$supervisor !== null) {
            self::$supervisor->endUnit($reason);

            return;
        }

        Report::event('UnitEnded', ['reason' => $reason]);
        RoleGuard::armWatchdog();

        exit(1);
    }

    /** Breaker: only workers that never reached their first loop, and weren't stopped by us, count. */
    public static function workerExited(ForkedProcess $process, int $status, bool $wasReady): void
    {
        if ($wasReady || $status === 0 || $process->parentSignalled || in_array($status, [137, 75, 72], true)) {
            return;
        }

        $now = microtime(true);

        if ($now - $process->spawnedAt > 120) {
            return;
        }

        $window = (float) Runtime::config('breaker_window', 300);
        self::$earlyExits = array_values(array_filter(self::$earlyExits, fn (float $at) => $now - $at < $window));
        self::$earlyExits[] = $now;

        if (count(self::$earlyExits) >= (int) Runtime::config('breaker_exits', 5)) {
            Report::event('BreakerTripped', ['scope' => 'unit', 'status' => $status]);

            try {
                if (Runtime::$statusDir !== null && Runtime::$unitId !== null) {
                    RuntimeDir::writeAtomic(Runtime::$statusDir, Runtime::$unitId.'.stock', 'breaker');
                }
            } catch (Throwable) {
            }

            self::endUnit('crash breaker');
        }
    }
}
