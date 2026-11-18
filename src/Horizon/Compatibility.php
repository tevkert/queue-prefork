<?php

namespace Tevkert\QueuePrefork\Horizon;

use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;

/**
 * The parts of Horizon this package extends or uses, checked by reflection rather than by version
 * number, so a new Horizon 5 release runs prefork as soon as it's installed when nothing the package
 * relies on changed.
 *
 * Every Horizon from 5.23 to 5.52 declares them exactly as listed here. Anything else (a method that
 * gained a parameter or a return type, a class made final, a property gone) is a reason to run stock.
 * It must be checked before a class extending Horizon's is loaded: PHP stops at an incompatible override
 * with a fatal error, which would keep Horizon from starting at all.
 *
 * Dependency-free apart from Horizon itself, which may be missing.
 */
final class Compatibility
{
    /** Classes the package extends: none may be final. */
    public const EXTENDED = [
        'Laravel\Horizon\Console\HorizonCommand',
        'Laravel\Horizon\MasterSupervisorCommands\AddSupervisor',
        'Laravel\Horizon\SupervisorFactory',
        'Laravel\Horizon\Supervisor',
        'Laravel\Horizon\ProcessPool',
    ];

    /** Methods the package overrides, as Horizon declares them; any difference at all means stock. */
    public const OVERRIDDEN = [
        'Laravel\Horizon\Console\HorizonCommand::handle' => 'public handle(Laravel\Horizon\Contracts\MasterSupervisorRepository $masters)',
        'Laravel\Horizon\MasterSupervisorCommands\AddSupervisor::createProcess' => 'protected createProcess(Laravel\Horizon\MasterSupervisor $master, Laravel\Horizon\SupervisorOptions $options)',
        'Laravel\Horizon\SupervisorFactory::make' => 'public make(Laravel\Horizon\SupervisorOptions $options)',
        'Laravel\Horizon\Supervisor::__construct' => 'public __construct(Laravel\Horizon\SupervisorOptions $options)',
        'Laravel\Horizon\Supervisor::createProcessPool' => 'protected createProcessPool(Laravel\Horizon\SupervisorOptions $options)',
        'Laravel\Horizon\Supervisor::pid' => 'public pid()',
        'Laravel\Horizon\Supervisor::listenForSignals' => 'protected listenForSignals()',
        'Laravel\Horizon\Supervisor::ensureParentIsRunning' => 'protected ensureParentIsRunning()',
        'Laravel\Horizon\Supervisor::shouldWait' => 'protected shouldWait()',
        'Laravel\Horizon\Supervisor::exitProcess' => 'protected exitProcess($status = …)',
        'Laravel\Horizon\ProcessPool::createProcess' => 'protected createProcess()',
    ];

    /**
     * Methods the package calls, with the arguments it passes: each must stay public and accept that
     * many arguments without requiring more. Their types may change.
     */
    public const CALLED = [
        'Laravel\Horizon\Supervisor::output' => 2,
        'Laravel\Horizon\Supervisor::terminate' => 1,
        'Laravel\Horizon\ProcessPool::__construct' => 2,
        'Laravel\Horizon\ProcessPool::processes' => 0,
        'Laravel\Horizon\SupervisorOptions::toSupervisorCommand' => 0,
        'Laravel\Horizon\SupervisorOptions::toWorkerCommand' => 0,
        'Laravel\Horizon\WorkerProcess::__construct' => 1,
        'Laravel\Horizon\MasterSupervisor::basename' => 0,
    ];

    /** Properties the package reads: each must stay public (and static where it is). */
    public const READ = [
        'Laravel\Horizon\Supervisor::$name' => 'public',
        'Laravel\Horizon\Supervisor::$processPools' => 'public',
        'Laravel\Horizon\ProcessPool::$options' => 'public',
        'Laravel\Horizon\ProcessPool::$terminatingProcesses' => 'public',
        'Laravel\Horizon\SupervisorOptions::$name' => 'public',
        'Laravel\Horizon\SupervisorOptions::$directory' => 'public',
        'Laravel\Horizon\WorkerProcess::$process' => 'public',
        'Laravel\Horizon\SupervisorCommandString::$command' => 'public static',
        'Laravel\Horizon\WorkerCommandString::$command' => 'public static',
    ];

    /** Events the package listens to. */
    public const EVENTS = [
        'Laravel\Horizon\Events\MasterSupervisorLooped',
        'Laravel\Horizon\Events\SupervisorLooped',
    ];

    /**
     * What keeps the master's command from being replaced: the one override loaded before preflight.
     *
     * @return list<string>
     */
    public static function commandReasons(): array
    {
        return self::reasons(
            ['Laravel\Horizon\Console\HorizonCommand'],
            array_intersect_key(self::OVERRIDDEN, ['Laravel\Horizon\Console\HorizonCommand::handle' => true]),
            [],
            [],
            [],
        );
    }

    /** @return list<string> everything that differs from what the package relies on */
    public static function reasons(
        array $extended = self::EXTENDED,
        array $overridden = self::OVERRIDDEN,
        array $called = self::CALLED,
        array $read = self::READ,
        array $events = self::EVENTS,
    ): array {
        $reasons = [];

        foreach ($extended as $class) {
            if (! class_exists($class)) {
                $reasons[] = "Horizon has no {$class}";
            } elseif ((new ReflectionClass($class))->isFinal()) {
                $reasons[] = "Horizon's {$class} is final";
            }
        }

        foreach ($overridden as $name => $expected) {
            $method = self::method($name);
            $actual = $method === null ? null : self::signature($method);

            if ($actual !== $expected) {
                $reasons[] = $actual === null
                    ? "Horizon has no {$name}()"
                    : "Horizon's {$name}() is now [{$actual}], the package overrides [{$expected}]";
            }
        }

        foreach ($called as $name => $arguments) {
            $method = self::method($name);

            if ($method === null) {
                $reasons[] = "Horizon has no {$name}()";
            } elseif (! $method->isPublic()) {
                $reasons[] = "Horizon's {$name}() is no longer public";
            } elseif ($method->getNumberOfRequiredParameters() > $arguments || (! $method->isVariadic() && $method->getNumberOfParameters() < $arguments)) {
                $reasons[] = "Horizon's {$name}() is now [".self::signature($method)."], the package passes {$arguments} argument(s)";
            }
        }

        foreach ($read as $name => $expected) {
            [$class, $property] = explode('::$', $name);

            try {
                $reflection = new ReflectionProperty($class, $property);
            } catch (ReflectionException) {
                $reasons[] = "Horizon has no {$name}";

                continue;
            }

            $actual = ($reflection->isPublic() ? 'public' : 'not public').($reflection->isStatic() ? ' static' : '');

            if ($actual !== $expected) {
                $reasons[] = "Horizon's {$name} is now {$actual}, the package reads it as {$expected}";
            }
        }

        foreach ($events as $class) {
            if (! class_exists($class)) {
                $reasons[] = "Horizon has no {$class}";
            }
        }

        return $reasons === [] ? [] : ['this Horizon changed what the package extends: '.implode('; ', $reasons)];
    }

    /** A method's declaration, as compared: modifiers, name, parameters with types and names, return type. */
    public static function signature(ReflectionMethod $method): string
    {
        $modifiers = implode(' ', array_filter([
            $method->isFinal() ? 'final' : null,
            $method->isAbstract() ? 'abstract' : null,
            $method->isPublic() ? 'public' : ($method->isProtected() ? 'protected' : 'private'),
            $method->isStatic() ? 'static' : null,
        ]));

        $parameters = array_map(static function (ReflectionParameter $parameter): string {
            return ltrim(
                self::type($parameter->getType()).' '
                .($parameter->isPassedByReference() ? '&' : '')
                .($parameter->isVariadic() ? '...' : '')
                .'$'.$parameter->getName()
                .($parameter->isDefaultValueAvailable() ? ' = …' : ''),
            );
        }, $method->getParameters());

        $return = $method->hasReturnType() ? ': '.self::type($method->getReturnType()) : '';

        return "{$modifiers} {$method->getName()}(".implode(', ', $parameters).')'.$return;
    }

    private static function method(string $name): ?ReflectionMethod
    {
        [$class, $method] = explode('::', $name);

        if (! class_exists($class)) {
            return null;
        }

        try {
            return new ReflectionMethod($class, $method);
        } catch (ReflectionException) {
            return null;
        }
    }

    private static function type(?ReflectionType $type): string
    {
        if ($type === null) {
            return '';
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull() && $type->getName() !== 'mixed' && $type->getName() !== 'null') {
            return '?'.$type->getName();
        }

        return $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;
    }
}
