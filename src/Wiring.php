<?php

namespace Tevkert\QueuePrefork;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Laravel\Horizon\Console\HorizonCommand;
use Laravel\Horizon\Events\MasterSupervisorLooped;
use Laravel\Horizon\Events\SupervisorLooped;
use Laravel\Horizon\SupervisorFactory;
use Tevkert\QueuePrefork\Console\ProbeCommand;
use Tevkert\QueuePrefork\Horizon\PreforkHorizonCommand;
use Tevkert\QueuePrefork\Horizon\PreforkSupervisorFactory;
use Tevkert\QueuePrefork\Server\RoleGuard;
use Tevkert\QueuePrefork\Support\StatusFiles;
use Tevkert\QueuePrefork\Support\Switches;
use Throwable;

/**
 * The container work behind src/hook.php, run from Artisan's "starting" callback: after the
 * application booted, before the command is dispatched.
 */
final class Wiring
{
    public static function starting($artisan, string $role): void
    {
        $app = $artisan->getLaravel();

        try {
            match ($role) {
                'master' => self::master($app),
                'probe' => $artisan->resolve(ProbeCommand::class),
                'child' => self::child($app),
                default => null,
            };
        } catch (Throwable $e) {
            if ($role === 'child') {
                throw $e; // a child that can't be wired must not run as an unwired stock process
            }

            error_log('queue-prefork wiring failed ('.$role.'): '.$e->getMessage());
        }
    }

    private static function master(Application $app): void
    {
        Settings::mergeDefaults($app);
        $app->singleton(Master::class);

        if (self::horizonOff($app) === null) {
            $app->bind(HorizonCommand::class, PreforkHorizonCommand::class);
        }
    }

    /** Why Horizon runs stock: an off switch (environment, .env or file), the runtime switch or the config. */
    private static function horizonOff(Application $app): ?string
    {
        $off = Switches::off(base_path(), 'horizon');

        if ($off !== []) {
            return implode('; ', $off);
        }

        if (Settings::enabled($app, 'horizon')) {
            return null;
        }

        return 'switched off ('.Settings::switchSource($app, 'horizon').')';
    }

    private static function child(Application $app): void
    {
        if (self::installedProtocol() !== Runtime::PROTOCOL) {
            // The package was updated under a running template: this child mixes versions. End it;
            // the unit is replaced from a fresh template.
            RoleGuard::shim(72);
        }

        if (Runtime::$role === 'horizon-master') {
            self::hostedMaster($app);

            return;
        }

        Settings::mergeDefaults($app);

        $app['events']->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (Runtime::$role === 'unit-loop' && Runtime::$statusDir !== null && Runtime::$unitId !== null) {
                StatusFiles::writeIntent(Runtime::$statusDir, Runtime::$unitId, (int) $event->exitCode);
            }

            RoleGuard::armWatchdog();
        });

        if (Runtime::$role === 'unit-loop') {
            self::supervisorLoop($app);
        } else {
            self::worker($app);
        }
    }

    private static function supervisorLoop(Application $app): void
    {
        $app->bind(SupervisorFactory::class, PreforkSupervisorFactory::class);
        $app['events']->listen(SupervisorLooped::class, fn () => Link::tick());
        Link::hello();
    }

    private static function worker(Application $app): void
    {
        $events = $app['events'];

        if (Runtime::$stubPid !== null || Runtime::$warmup) {
            // A queue:work worker forked from the shared template, or its warm-up boot. The app has
            // booted and the command hasn't started: the warm-up leaves and the post-boot checks run
            // here, where no event listener can skip them.
            QueueWorkerLink::booted($app);
            $events->listen(Looping::class, fn () => QueueWorkerLink::looping());
        } else {
            $events->listen(Looping::class, [WorkerLink::class, 'looping']);
        }

        $events->listen(WorkerStopping::class, fn () => RoleGuard::armWatchdog());
    }

    /**
     * The Horizon master forked from its host template. Switched off after boot, or
     * unable to wire, it asks the host to run the stock master in its place and leaves before Horizon
     * starts. Whatever runs Horizon's loop then, it ends when the host is gone.
     */
    private static function hostedMaster(Application $app): void
    {
        try {
            Settings::mergeDefaults($app);
            $off = self::horizonOff($app);

            if ($off !== null) {
                Master::handBack($off);
            }

            $app->singleton(Master::class);
            $app->bind(HorizonCommand::class, PreforkHorizonCommand::class);
            $app['events']->listen(CommandFinished::class, fn () => RoleGuard::armWatchdog());
            $app['events']->listen(MasterSupervisorLooped::class, fn () => $app->make(Master::class)->watchHost());
        } catch (Throwable $e) {
            Master::handBack(get_class($e).': '.$e->getMessage());
        }
    }

    private static function installedProtocol(): int
    {
        $source = (string) @file_get_contents(__DIR__.'/Runtime.php');

        return preg_match('/const PROTOCOL = (\d+);/', $source, $m) ? (int) $m[1] : Runtime::PROTOCOL;
    }
}
