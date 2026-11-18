<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tevkert\QueuePrefork\Horizon\Compatibility;

class CompatibilityTest extends TestCase
{
    private const SUBCLASSES = [
        'Tevkert\QueuePrefork\Horizon\PreforkHorizonCommand' => 'Laravel\Horizon\Console\HorizonCommand',
        'Tevkert\QueuePrefork\Horizon\PreforkAddSupervisor' => 'Laravel\Horizon\MasterSupervisorCommands\AddSupervisor',
        'Tevkert\QueuePrefork\Horizon\PreforkSupervisorFactory' => 'Laravel\Horizon\SupervisorFactory',
        'Tevkert\QueuePrefork\Horizon\PreforkSupervisor' => 'Laravel\Horizon\Supervisor',
        'Tevkert\QueuePrefork\Horizon\PreforkProcessPool' => 'Laravel\Horizon\ProcessPool',
    ];

    public function test_the_installed_horizon_is_what_the_package_extends(): void
    {
        $this->assertSame([], Compatibility::reasons());
        $this->assertSame([], Compatibility::commandReasons());
    }

    public function test_the_lists_cover_every_member_the_subclasses_declare(): void
    {
        $overridden = [];
        $added = [];

        foreach (self::SUBCLASSES as $child => $parent) {
            $reflection = new ReflectionClass($child);
            $this->assertSame($parent, $reflection->getParentClass()->getName(), $child);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $child) {
                    continue;
                }

                $name = $parent.'::'.$method->getName();

                if (method_exists($parent, $method->getName())) {
                    $overridden[] = $name;
                    // Each override is declared as the parent's: if one changes, the list changes with it.
                    $this->assertSame(Compatibility::OVERRIDDEN[$name] ?? null, Compatibility::signature($method), $name);
                } else {
                    $added[] = $name;
                }
            }

            foreach ($reflection->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() === $child) {
                    $this->assertFalse(property_exists($parent, $property->getName()), "{$child} redeclares \${$property->getName()}");
                    $added[] = $parent.'::$'.$property->getName();
                }
            }
        }

        sort($overridden);
        sort($added);
        $this->assertSame($this->sorted(array_keys(Compatibility::OVERRIDDEN)), $overridden);
        $this->assertSame($this->sorted(Compatibility::ADDED), $added);
        $this->assertSame($this->sorted(array_values(self::SUBCLASSES)), $this->sorted(Compatibility::EXTENDED));
    }

    public function test_a_method_that_now_returns_by_reference_is_a_reason(): void
    {
        $reasons = Compatibility::reasons([], [CompatibilityParent::class.'::byReference' => 'public byReference()'], [], [], [], []);

        $this->assertStringContainsString('is now [public &byReference()]', $reasons[0] ?? '');
    }

    public function test_a_changed_declaration_is_a_reason(): void
    {
        $reasons = Compatibility::reasons(
            [CompatibilityFinal::class],
            [
                CompatibilityParent::class.'::typed' => 'protected typed($status = …)',
                CompatibilityParent::class.'::gone' => 'public gone()',
                CompatibilityParent::class.'::same' => 'public same(?string $line = …)',
            ],
            [CompatibilityParent::class.'::needsTwo' => 1, CompatibilityParent::class.'::typed' => 0],
            [CompatibilityParent::class.'::$hidden' => 'public', CompatibilityParent::class.'::$shared' => 'public static'],
            [CompatibilityParent::class.'::$shared', CompatibilityParent::class.'::same', CompatibilityParent::class.'::ours'],
            ['Missing\Event'],
        );

        $this->assertCount(1, $reasons);
        $reason = $reasons[0];
        $this->assertStringContainsString('CompatibilityFinal is final', $reason);
        $this->assertStringContainsString('::typed() is now [protected typed(int $status = …): void]', $reason);
        $this->assertStringContainsString('has no '.CompatibilityParent::class.'::gone()', $reason);
        $this->assertStringNotContainsString('::same', $reason);
        $this->assertStringContainsString('::needsTwo() is now [public needsTwo($a, $b)], the package passes 1 argument(s)', $reason);
        $this->assertStringContainsString('::typed() is no longer public', $reason);
        $this->assertStringContainsString('::$hidden is now not public', $reason);
        $this->assertStringNotContainsString('::$shared is now', $reason);
        $this->assertStringContainsString('now declares $shared itself', $reason);
        $this->assertStringContainsString('now declares same itself', $reason);
        $this->assertStringNotContainsString('declares ours', $reason);
        $this->assertStringContainsString('has no Missing\Event', $reason);
    }

    private function sorted(array $list): array
    {
        sort($list);

        return $list;
    }
}

final class CompatibilityFinal {}

class CompatibilityParent
{
    public static $shared;

    protected $hidden;

    protected function typed(int $status = 0): void {}

    public function same(?string $line = null) {}

    public function needsTwo($a, $b) {}

    public function &byReference() {}
}
