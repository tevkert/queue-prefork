<?php

namespace Tevkert\QueuePrefork\Support;

use php_user_filter;

/**
 * A write filter that holds output until a newline, then passes each complete line on as its own
 * write.
 *
 * Forked Horizon processes share the master's stdout, and shared-log workers one log file, and
 * `queue:work` writes every job line in three or four pieces, so pieces from different processes
 * interleave mid-line. A pipe delivers a write of up to PIPE_BUF (4096 bytes on Linux) in one piece,
 * and an append-mode file takes each write at its end, so a line written whole, up to that size,
 * reaches the log whole.
 *
 * A partial line longer than MAX_HELD is passed on rather than held without bound, and whatever is
 * held when the stream is closed or freed, at exit included, is passed on then.
 */
final class LineBufferFilter extends php_user_filter
{
    public const NAME = 'queue-prefork.line-buffer';

    /** The most a partial line is held before it's passed on as is: PIPE_BUF on Linux. */
    public const MAX_HELD = 4096;

    private string $held = '';

    private int $pid = 0;

    public function onCreate(): bool
    {
        $this->pid = getmypid();

        return true;
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        if ($this->pid !== getmypid()) {
            // A process forked after this filter was attached (a job's own pcntl_fork()) inherits what
            // the parent held, and the parent passes that on itself.
            $this->pid = getmypid();
            $this->held = '';
        }

        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->held .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        $passed = false;
        $start = 0;

        while (($end = strpos($this->held, "\n", $start)) !== false) {
            $passed = $this->pass($out, substr($this->held, $start, $end + 1 - $start)) || $passed;
            $start = $end + 1;
        }

        if ($start > 0) {
            $this->held = substr($this->held, $start);
        }

        if ($this->held !== '' && ($closing || strlen($this->held) >= self::MAX_HELD)) {
            $passed = $this->pass($out, $this->held) || $passed;
            $this->held = '';
        }

        return $passed ? PSFS_PASS_ON : PSFS_FEED_ME;
    }

    /** One bucket per line: the stream writes each bucket with its own write call. */
    private function pass($out, string $data): bool
    {
        if (is_resource($this->stream)) {
            stream_bucket_append($out, stream_bucket_new($this->stream, $data));

            return true;
        }

        // A stream freed without fclose() (STDOUT at exit, or any stream whose last reference
        // goes) is invalidated before this last flush. A bucket only needs some stream to be made
        // against, and still goes to the stream being freed. If even that fails, drop what's left
        // rather than throw, which at exit would turn the status into 255.
        $stream = @fopen('php://memory', 'r');

        if ($stream === false) {
            return false;
        }

        try {
            stream_bucket_append($out, stream_bucket_new($stream, $data));
        } catch (\Throwable) {
            return false;
        } finally {
            fclose($stream);
        }

        return true;
    }
}
