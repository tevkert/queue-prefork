<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tevkert\QueuePrefork\Handoff;
use Tevkert\QueuePrefork\Support\Native;

/** The handoff's reading of the template's replies. */
class HandoffTest extends TestCase
{
    public function test_replies_that_arrive_together_or_in_pieces_are_read_one_at_a_time(): void
    {
        if (! Native::available()) {
            $this->markTestSkipped('needs FFI: '.Native::reason());
        }

        $native = Native::get();
        [$ours, $theirs] = $native->socketPair();
        $native->setTimeouts($ours, 2);
        $handoff = (new ReflectionClass(Handoff::class))->newInstanceWithoutConstructor();
        $set = fn (string $name, mixed $value) => (new \ReflectionProperty(Handoff::class, $name))->setValue($handoff, $value);
        $set('mode', 'normal');
        $set('fd', $ours);
        $receive = fn () => (new \ReflectionMethod(Handoff::class, 'receiveLine'))->invoke($handoff, 2.0);

        $native->send($theirs, "{\"t\":\"waiting\"}\n{\"t\":\"spawned\",\"pid\":7}\n{\"t\":");
        $this->assertSame(['t' => 'waiting'], $receive());
        $this->assertSame(['t' => 'spawned', 'pid' => 7], $receive(), 'the second reply of the same read');

        $native->send($theirs, "\"status\"}\n");
        $this->assertSame(['t' => 'status'], $receive(), 'a reply split across reads');

        $native->close($theirs);
        $this->assertSame([], $receive(), 'EOF');
        $native->close($ours);
    }
}
