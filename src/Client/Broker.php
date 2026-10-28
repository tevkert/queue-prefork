<?php

namespace Tevkert\QueuePrefork\Client;

use Closure;
use Tevkert\QueuePrefork\Support\Channel;

/**
 * The client side of a channel to a fork server: master → template, or supervisor loop → fork server.
 *
 * It routes replies to process handles and judges the server's liveness only by its requests: a
 * request is overdue once it has waited longer than the spawn timeout *and* this client has completed
 * at least five drain rounds since sending it, rounds at least 1 s apart. A gap of more than 2 s between
 * this client's own pumps (swap, SIGSTOP, a VM pause) resets the count, so a paused client never blames
 * a healthy server.
 */
final class Broker
{
    /** @var array<string, ForkedProcess> */
    private array $handles = [];

    /** @var array<string, array{at: float, rounds: int}> */
    private array $outstanding = [];

    private int $sequence = 0;

    private float $lastPump = 0.0;

    private float $lastRound = 0.0;

    private float $lastPing = 0.0;

    private bool $overdueReported = false;

    public bool $lost = false;

    /** Messages that aren't replies (log, opcache, ready from the template, …). */
    public ?Closure $onMessage = null;

    public ?Closure $onLost = null;

    public ?Closure $onOverdue = null;

    public function __construct(
        private Channel $channel,
        private string $prefix,
        private float $spawnTimeout = 10.0,
        private float $pingEvery = 30.0,
    ) {}

    public function channel(): Channel
    {
        return $this->channel;
    }

    public function nextId(): string
    {
        return $this->prefix.(++$this->sequence).'-'.getmypid();
    }

    public function register(string $id, ForkedProcess $handle): void
    {
        $this->handles[$id] = $handle;
    }

    /** Drops a finished handle's id: late messages for it are ignored, and its requests aren't awaited. */
    public function forget(string $id): void
    {
        unset($this->handles[$id]);
        $this->resolve("spawn:{$id}");
        $this->resolve("signal:{$id}");
    }

    /** @return array<string, ForkedProcess> */
    public function handles(): array
    {
        return $this->handles;
    }

    public function send(array $message): void
    {
        if (! $this->lost) {
            $this->channel->send($message);
        }
    }

    /** Sends a message that expects a reply, and tracks it for liveness. */
    public function request(string $key, array $message): void
    {
        if ($this->lost) {
            return;
        }

        $this->outstanding[$key] = ['at' => self::now(), 'rounds' => 0];
        $this->channel->send($message);
    }

    public function resolve(string $key): void
    {
        unset($this->outstanding[$key]);
    }

    public function pump(): void
    {
        if ($this->lost) {
            return;
        }

        $now = self::now();

        if ($this->lastPump > 0 && $now - $this->lastPump > 2.0) {
            foreach ($this->outstanding as $key => $request) {
                $this->outstanding[$key]['rounds'] = 0;
            }
        }

        $this->lastPump = $now;
        $messages = $this->channel->receive();

        if ($now - $this->lastRound >= 1.0) {
            $this->lastRound = $now;

            foreach ($this->outstanding as $key => $request) {
                $this->outstanding[$key]['rounds']++;
            }
        }

        foreach ($messages as $message) {
            $this->dispatch($message);
        }

        $this->channel->flush();

        if ($this->channel->eof) {
            $this->markLost();

            return;
        }

        if ($this->pingEvery > 0 && $now - $this->lastPing >= $this->pingEvery) {
            $this->lastPing = $now;
            $id = $this->nextId();
            $this->request("ping:{$id}", ['t' => 'ping', 'id' => $id]);
        }

        foreach ($this->outstanding as $request) {
            if ($now - $request['at'] > $this->spawnTimeout && $request['rounds'] >= 5) {
                if (! $this->overdueReported) {
                    $this->overdueReported = true;
                    ($this->onOverdue)?->__invoke();
                }

                return;
            }
        }
    }

    /**
     * Pumps until $done() holds or $seconds pass, waiting on the channel in between (never spinning).
     * For replies that normally arrive within milliseconds, so they need not wait for the next tick.
     */
    public function pumpUntil(callable $done, float $seconds): void
    {
        $deadline = self::now() + $seconds;
        $this->pump();

        while (! $this->lost && ! $done() && ($left = $deadline - self::now()) > 0) {
            $this->channel->wait($left);
            $this->pump();
        }
    }

    public function markLost(): void
    {
        if ($this->lost) {
            return;
        }

        $this->lost = true;
        $this->outstanding = [];

        foreach ($this->handles as $handle) {
            $handle->serverLost();
        }

        ($this->onLost)?->__invoke();
    }

    /** Lets queued writes out before this process exits. */
    public function drain(float $seconds): bool
    {
        return $this->lost || $this->channel->flushBlocking($seconds);
    }

    private function dispatch(array $message): void
    {
        $id = (string) ($message['id'] ?? '');
        $handle = $this->handles[$id] ?? null;

        switch ($message['t'] ?? null) {
            case 'spawned':
                $this->resolve("spawn:{$id}");
                $handle?->spawned($message);
                break;

            case 'spawn_failed':
                $this->resolve("spawn:{$id}");
                $handle?->spawnFailed((string) ($message['reason'] ?? ''));
                break;

            case 'exited':
                $handle?->exited((int) $message['status'], (bool) ($message['ready'] ?? true));
                unset($this->handles[$id]);
                break;

            case 'ready':
                $handle?->ready();
                break;

            case 'signalled':
                $this->resolve("signal:{$id}");
                break;

            case 'pong':
                $this->resolve("ping:{$id}");
                break;

            default:
                ($this->onMessage)?->__invoke($message);
        }
    }

    public static function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
