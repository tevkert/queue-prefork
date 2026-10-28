<?php

namespace Tevkert\QueuePrefork;

/**
 * Process-wide prefork state.
 *
 * Dependency-free on purpose: the template process loads it before any application code exists, and
 * every forked child inherits the values the template and its fork server set here.
 */
final class Runtime
{
    /** Bumped whenever the template/fork-server/child protocol changes (checked by children). */
    public const PROTOCOL = 2;

    /** The package's classes that don't extend the framework's: what preload() loads. */
    public const CLASSES = [
        Client\Broker::class, Client\Direct::class, Client\Report::class, Handoff::class, Link::class, Master::class,
        MasterHandoff::class, Preflight::class, QueueWorkerLink::class, Runtime::class, Settings::class, Wiring::class,
        WorkerLink::class, Server\ChildJob::class, Server\ChildRoutine::class, Server\Opcache::class, Server\RoleGuard::class,
        Server\SharedLog::class, Server\Signals::class, Server\Template::class, Server\UnitServer::class,
        Server\WorkServer::class, Server\WorkerContext::class, Support\Audit::class, Support\Channel::class,
        Support\CommandLine::class, Support\DotEnv::class, Support\EarlyConfig::class, Support\Fd::class,
        Support\Identity::class, Support\LogTargets::class, Support\Native::class, Support\Process::class,
        Support\RuntimeDir::class, Support\StatusFiles::class, Support\Switches::class, Support\TemplateKey::class,
        Support\WorkTemplates::class,
    ];

    /** template | fork-server | unit-loop | worker | horizon-master, or null in an ordinary process. */
    public static ?string $role = null;

    /** The spawn request id this child was created for. */
    public static ?string $requestId = null;

    /** Identity of this child's fork server, captured at admission. */
    public static ?int $serverPid = null;

    public static ?string $serverStart = null;

    /** This child's own channel to its fork server (a supervisor loop's socket, a worker's startup socket). */
    public static ?Support\Channel $channel = null;

    /** Host mode: the template the Horizon master was forked from (its parent). */
    public static ?int $hostPid = null;

    public static ?string $hostStart = null;

    /** Identity of the Horizon master. */
    public static ?int $masterPid = null;

    public static ?string $masterStart = null;

    /** Secure per-user runtime directory and this master's status directory inside it. */
    public static ?string $runtimeDir = null;

    public static ?string $statusDir = null;

    /** Package settings handed down from the master (timeouts, opcache sizes, …). */
    public static array $config = [];

    /** A forked child's original auto_prepend_file (the template's own is off), passed on to what it spawns. */
    public static ?string $prepend = null;

    /** Desired pause state of a worker at admission. */
    public static bool $paused = false;

    /** The fork server's id string for status files: "<F pid>-<F start>". */
    public static ?string $unitId = null;

    /** queue:work children: the stub's identity, the output mode and the stub's original
     * stdout/stderr identities [[dev, ino], [dev, ino]] for the stdio-logging check. */
    public static ?int $stubPid = null;

    public static ?string $stubStart = null;

    public static ?string $output = null;

    /** @var list<array{0: int, 1: int}> */
    public static array $stubStdio = [];

    /** The warm-up child of a fresh template: boots the app, then exits at its first loop (no job). */
    public static bool $warmup = false;

    public static function isServer(): bool
    {
        return self::$role === 'template' || self::$role === 'fork-server';
    }

    public static function isChild(): bool
    {
        return self::$role === 'unit-loop' || self::$role === 'worker' || self::$role === 'horizon-master';
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        return self::$config[$key] ?? $default;
    }

    /**
     * Loads the package's classes now. A deploy can replace or delete their files while a template or the
     * Horizon master still runs, and a class first needed at shutdown would then come from another version,
     * or fail to load and keep the process from exiting.
     */
    public static function preload(): void
    {
        foreach (self::CLASSES as $class) {
            class_exists($class);
        }
    }
}
