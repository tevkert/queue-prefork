<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Audit;

class AuditTest extends TestCase
{
    private const GRPC = ['anon_inode:[eventpoll]' => 1, 'anon_inode:[eventfd]' => 1];

    public function test_without_a_fork_aware_extension_every_flagged_descriptor_is_a_reason(): void
    {
        $kinds = [6 => 'anon_inode:[eventpoll]', 7 => 'anon_inode:[eventfd]'];

        $this->assertSame(['fd 6: anon_inode:[eventpoll]', 'fd 7: anon_inode:[eventfd]'], Audit::unsafe($kinds, [], []));
    }

    public function test_grpc_with_fork_support_excuses_its_poller(): void
    {
        $kinds = [13 => 'anon_inode:[eventpoll]', 14 => 'anon_inode:[eventfd]'];

        $this->assertSame([], Audit::unsafe($kinds, [], self::GRPC));
    }

    public function test_descriptors_beyond_the_poller_are_still_flagged(): void
    {
        // e.g. php-zmq's eventfd next to gRPC's poller, or a socket an extension opened at startup
        $kinds = [5 => 'anon_inode:[eventfd]', 6 => 'anon_inode:[eventpoll]', 7 => 'anon_inode:[eventfd]', 8 => 'socket socket:[4242]'];

        $this->assertSame(['fd 7: anon_inode:[eventfd]', 'fd 8: socket socket:[4242]'], Audit::unsafe($kinds, [], self::GRPC));
    }

    public function test_allowed_patterns_apply_before_the_excuse(): void
    {
        $kinds = [5 => 'socket socket:[4242]', 6 => 'anon_inode:[eventfd]', 7 => 'anon_inode:[eventfd]'];

        $this->assertSame(['fd 7: anon_inode:[eventfd]'], Audit::unsafe($kinds, ['socket*'], self::GRPC));
        $this->assertSame([], Audit::unsafe($kinds, ['socket*', 'anon_inode:*'], []));
    }

    public function test_nothing_is_excused_without_grpc_fork_support(): void
    {
        if (extension_loaded('grpc') && ini_get('grpc.enable_fork_support')) {
            $this->markTestSkipped('this PHP runs gRPC with fork support');
        }

        $this->assertSame([], Audit::forkAwareDescriptors());
    }
}
