<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Client\Broker;
use Tevkert\QueuePrefork\Client\ForkedProcess;
use Tevkert\QueuePrefork\Support\Channel;
use Tevkert\QueuePrefork\Support\Identity;

/**
 * The client state machine against a scripted server on the other end of a socket pair.
 */
class ForkedProcessTest extends TestCase
{
    private Broker $broker;

    private Channel $server;

    protected function setUp(): void
    {
        [$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->broker = new Broker(new Channel($a), 'w', 10.0, 0);
        $this->server = new Channel($b);
    }

    public function test_a_finished_worker_starts_again_under_a_new_id(): void
    {
        $worker = $this->worker();
        $worker->start();
        $first = $this->expect('spawn');

        $this->reply(['t' => 'spawned', 'id' => $first['id'], 'pid' => getmypid(), 'start' => Identity::startTime(getmypid())]);
        $this->assertTrue($worker->isRunning());
        $this->assertSame(['t' => 'admit', 'id' => $first['id'], 'paused' => false], $this->expect('admit'));

        $this->reply(['t' => 'exited', 'id' => $first['id'], 'status' => 7, 'ready' => true]);
        $this->assertFalse($worker->isRunning());
        $this->assertSame(7, $worker->getExitCode());

        // Horizon's WorkerProcess::restart() calls start() on the same handle.
        $worker->start();
        $second = $this->expect('spawn');
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertTrue($worker->isRunning());
        $this->assertNull($worker->getExitCode(), 'nothing kept from the last run');
        $this->assertNull($worker->getPid());

        // A late message for the old run is ignored.
        $this->reply(['t' => 'exited', 'id' => $first['id'], 'status' => 9, 'ready' => true]);
        $this->assertTrue($worker->isRunning());

        $this->reply(['t' => 'spawned', 'id' => $second['id'], 'pid' => getmypid(), 'start' => Identity::startTime(getmypid())]);
        $this->reply(['t' => 'exited', 'id' => $second['id'], 'status' => 0, 'ready' => true]);
        $this->assertFalse($worker->isRunning());
        $this->assertSame(0, $worker->getExitCode());
    }

    public function test_a_cancelled_spawn_starts_again(): void
    {
        $worker = $this->worker();
        $worker->start();
        $first = $this->expect('spawn');

        $worker->signal(SIGTERM);
        $this->assertSame(['t' => 'cancel', 'id' => $first['id']], $this->expect('cancel'));
        $this->assertFalse($worker->isRunning());
        $this->assertSame(128 + SIGTERM, $worker->getExitCode());

        $worker->start();
        $this->assertNotSame($first['id'], $this->expect('spawn')['id']);
        $this->assertTrue($worker->isRunning());
    }

    public function test_a_pause_before_admission_is_carried_into_it(): void
    {
        $worker = $this->worker();
        $worker->start();
        $spawn = $this->expect('spawn');

        $worker->signal(SIGUSR2);
        $this->reply(['t' => 'spawned', 'id' => $spawn['id'], 'pid' => getmypid(), 'start' => Identity::startTime(getmypid())]);
        $worker->isRunning();

        $this->assertSame(['t' => 'admit', 'id' => $spawn['id'], 'paused' => true], $this->expect('admit'));
    }

    public function test_a_prompt_reply_is_admitted_within_start(): void
    {
        $server = pcntl_fork();

        if ($server === 0) {
            // A fork server that answers at once: spawned for the request, then expects the admit.
            $spawn = $this->server->receiveOne(5.0);
            $this->server->send(['t' => 'spawned', 'id' => $spawn['id'], 'pid' => getmypid(), 'start' => Identity::startTime(getmypid())]);
            $this->server->flushBlocking(1.0);
            $admit = $this->server->receiveOne(5.0);
            exit(($admit['t'] ?? null) === 'admit' ? 0 : 1);
        }

        $worker = $this->worker();
        $began = microtime(true);
        $worker->start();

        $this->assertSame($server, $worker->getPid(), 'admitted before start() returned, without a later tick');
        $this->assertLessThan(0.05, microtime(true) - $began);
        pcntl_waitpid($server, $status);
        $this->assertSame(0, pcntl_wexitstatus($status), 'the server received the admit');
    }

    private function worker(): ForkedProcess
    {
        $worker = new ForkedProcess($this->broker, 'worker', 'php artisan horizon:work redis', '/tmp', false);
        $worker->onExit = fn () => null;

        return $worker;
    }

    private function reply(array $message): void
    {
        $this->server->send($message);
        $this->server->flushBlocking(1.0);
        usleep(20000);
        $this->broker->pump();
    }

    private function expect(string $type): array
    {
        $deadline = microtime(true) + 2;

        while (microtime(true) < $deadline) {
            $this->broker->pump();
            $message = $this->server->receiveOne(0.05);

            if ($message !== null && $message['t'] === $type) {
                return $message;
            }
        }

        $this->fail("no {$type} message");
    }
}
