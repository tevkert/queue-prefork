<?php

namespace Tevkert\QueuePrefork\Support;

use RuntimeException;

/**
 * Newline-delimited JSON over a Unix stream socket. Dependency-free.
 *
 * Writes never block: they go to a bounded queue that flush() drains as far as the socket accepts.
 * Reads drain whatever is available. `log` messages are the first to be dropped when the queue is full.
 */
final class Channel
{
    public const MAX_MESSAGE = 65536;

    public const MAX_QUEUE = 1048576;

    /** @var resource */
    private $stream;

    private string $in = '';

    private string $out = '';

    /** @var list<array> decoded messages not yet handed out */
    private array $ready = [];

    public bool $eof = false;

    /** @param resource $stream */
    public function __construct($stream)
    {
        if (! is_resource($stream)) {
            throw new RuntimeException('Channel needs an open stream.');
        }

        $this->stream = $stream;
        stream_set_blocking($stream, false);
        stream_set_write_buffer($stream, 0);
        stream_set_read_buffer($stream, 0);
    }

    /** @return resource */
    public function stream()
    {
        return $this->stream;
    }

    public function isOpen(): bool
    {
        return is_resource($this->stream) && ! $this->eof;
    }

    public function send(array $message): void
    {
        if (! is_resource($this->stream)) {
            return;
        }

        $line = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";

        if (strlen($line) > self::MAX_MESSAGE) {
            throw new RuntimeException('Channel message too large: '.strlen($line).' bytes.');
        }

        if (strlen($this->out) + strlen($line) > self::MAX_QUEUE) {
            if (($message['t'] ?? null) === 'log') {
                return;
            }

            $this->dropLogs();
        }

        $this->out .= $line;
        $this->flush();
    }

    /** Writes as much of the queue as the socket accepts now. True when the queue is empty. */
    public function flush(): bool
    {
        while ($this->out !== '' && is_resource($this->stream)) {
            $written = @fwrite($this->stream, $this->out);

            if ($written === false || $written === 0) {
                break;
            }

            $this->out = (string) substr($this->out, $written);
        }

        return $this->out === '';
    }

    public function wantsWrite(): bool
    {
        return $this->out !== '';
    }

    /** Waits (bounded) until the write queue is empty. */
    public function flushBlocking(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;

        while (! $this->flush() && is_resource($this->stream)) {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                return false;
            }

            $read = null;
            $write = [$this->stream];
            $except = null;
            @stream_select($read, $write, $except, 0, (int) min(200000, $left * 1e6));
        }

        return true;
    }

    /** @return list<array> every complete message available now */
    public function receive(): array
    {
        $this->fill();
        $messages = $this->ready;
        $this->ready = [];

        return $messages;
    }

    private function fill(): void
    {
        if (! is_resource($this->stream)) {
            $this->eof = true;

            return;
        }

        while (true) {
            $chunk = @fread($this->stream, 65536);

            if ($chunk === false || $chunk === '') {
                if (feof($this->stream)) {
                    $this->eof = true;
                }

                break;
            }

            $this->in .= $chunk;

            if (strlen($this->in) > self::MAX_QUEUE) {
                throw new RuntimeException('Channel input overflow.');
            }
        }

        while (($pos = strpos($this->in, "\n")) !== false) {
            $line = substr($this->in, 0, $pos);
            $this->in = (string) substr($this->in, $pos + 1);
            $decoded = json_decode($line, true);

            if (is_array($decoded)) {
                $this->ready[] = $decoded;
            }
        }
    }

    /** Blocks until one message arrives, or EOF/timeout. Null on EOF or timeout. */
    /** Waits up to $seconds for the peer to send something, flushing queued output meanwhile. */
    public function wait(float $seconds): void
    {
        if ($this->ready !== [] || $this->eof || ! is_resource($this->stream) || $seconds <= 0) {
            return;
        }

        $read = [$this->stream];
        $write = $this->out !== '' ? [$this->stream] : null;
        $except = null;
        @stream_select($read, $write, $except, 0, (int) min(1000000, $seconds * 1e6));
        $this->flush();
    }

    public function receiveOne(?float $timeout = null): ?array
    {
        $deadline = $timeout === null ? null : microtime(true) + $timeout;

        while (true) {
            $this->fill();

            if ($this->ready !== []) {
                return array_shift($this->ready);
            }

            if ($this->eof) {
                return null;
            }

            $left = $deadline === null ? 1.0 : $deadline - microtime(true);

            if ($left <= 0) {
                return null;
            }

            $read = [$this->stream];
            $write = $this->out !== '' ? [$this->stream] : null;
            $except = null;
            @stream_select($read, $write, $except, 0, (int) min(1000000, $left * 1e6));
            $this->flush();
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }

        $this->eof = true;
    }

    private function dropLogs(): void
    {
        $kept = [];

        foreach (explode("\n", rtrim($this->out, "\n")) as $line) {
            if ($line !== '' && ! str_contains(substr($line, 0, 16), '"t":"log"')) {
                $kept[] = $line;
            }
        }

        $this->out = $kept === [] ? '' : implode("\n", $kept)."\n";

        if (strlen($this->out) > self::MAX_QUEUE) {
            throw new RuntimeException('Channel output queue overflow.');
        }
    }
}
