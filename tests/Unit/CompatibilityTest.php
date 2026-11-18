<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Tevkert\QueuePrefork\Horizon\Compatibility;

class CompatibilityTest extends TestCase
{
    public function test_the_installed_horizon_is_what_the_package_extends(): void
    {
        $this->assertSame([], Compatibility::reasons());
        $this->assertSame([], Compatibility::commandReasons());
    }

    public function test_the_overrides_match_the_declarations_they_were_written_against(): void
    {
        // Each override must be declared as the parent's: if one changes, the list must change with it.
        $overrides = [
            'Laravel\Horizon\Console\HorizonCommand::handle' => 'Tevkert\QueuePrefork\Horizon\PreforkHorizonCommand',
            'Laravel\Horizon\MasterSupervisorCommands\AddSupervisor::createProcess' => 'Tevkert\QueuePrefork\Horizon\PreforkAddSupervisor',
            'Laravel\Horizon\SupervisorFactory::make' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisorFactory',
            'Laravel\Horizon\Supervisor::__construct' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::createProcessPool' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::pid' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::listenForSignals' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::ensureParentIsRunning' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::shouldWait' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\Supervisor::exitProcess' => 'Tevkert\QueuePrefork\Horizon\PreforkSupervisor',
            'Laravel\Horizon\ProcessPool::createProcess' => 'Tevkert\QueuePrefork\Horizon\PreforkProcessPool',
        ];

        $this->assertSame(array_keys(Compatibility::OVERRIDDEN), array_keys($overrides));

        foreach ($overrides as $parent => $child) {
            $method = new ReflectionMethod($child, explode('::', $parent)[1]);
            $this->assertSame($child, $method->getDeclaringClass()->getName(), "{$child} overrides {$parent}");
            $this->assertSame(Compatibility::OVERRIDDEN[$parent], Compatibility::signature($method), $parent);
        }
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
        $this->assertStringNotContainsString('$shared', $reason);
        $this->assertStringContainsString('has no Missing\Event', $reason);
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
}
