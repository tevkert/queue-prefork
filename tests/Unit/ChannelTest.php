<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Channel;

class ChannelTest extends TestCase
{
    public function test_round_trip_and_partial_lines(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $left = new Channel($a);
        $right = new Channel($b);

        $left->send(['t' => 'spawn', 'id' => 'u1']);
        fwrite($a, '{"t":"pi');
        $this->assertSame([['t' => 'spawn', 'id' => 'u1']], $this->soon($right));

        fwrite($a, "ng\"}\n");
        $this->assertSame([['t' => 'ping']], $this->soon($right));
    }

    private function soon(Channel $channel): array
    {
        $deadline = microtime(true) + 1;

        do {
            $messages = $channel->receive();

            if ($messages !== []) {
                return $messages;
            }

            usleep(10000);
        } while (microtime(true) < $deadline);

        return [];
    }

    public function test_receive_one_keeps_the_rest_queued(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $left = new Channel($a);
        $right = new Channel($b);
        $left->send(['n' => 1]);
        $left->send(['n' => 2]);

        $this->assertSame(['n' => 1], $right->receiveOne(1.0));
        $this->assertSame(['n' => 2], $right->receiveOne(1.0));
        $this->assertNull($right->receiveOne(0.1));
    }

    public function test_eof_is_seen(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $right = new Channel($b);
        fclose($a);

        $this->assertNull($right->receiveOne(1.0));
        $this->assertTrue($right->eof);
    }

    public function test_writes_never_block_and_queue_until_the_peer_reads(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $left = new Channel($a);
        $right = new Channel($b);
        $big = str_repeat('x', 60000);
        $start = microtime(true);

        for ($i = 0; $i < 12; $i++) {
            $left->send(['t' => 'data', 'v' => $big]);
        }

        $this->assertLessThan(1.0, microtime(true) - $start, 'send() must not block on a full socket');

        $got = 0;
        $deadline = microtime(true) + 5;

        while ($got < 12 && microtime(true) < $deadline) {
            $left->flush();
            $got += count($right->receive());
            usleep(10000);
        }

        $this->assertSame(12, $got);
    }
}
