<?php

namespace Tevkert\QueuePrefork;

use Tevkert\QueuePrefork\Client\Breaker;
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

    private static ?Breaker $breaker = null;

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

    /** The unit's crash breaker (Client\Breaker): tripped, the unit ends and its supervisor runs stock. */
    public static function workerExited(ForkedProcess $process, int $status, bool $wasReady): void
    {
        if ((self::$breaker ??= new Breaker)->trips($process, $status, $wasReady)) {
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
