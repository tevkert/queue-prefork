<?php

namespace Tevkert\QueuePrefork\Support;

use php_user_filter;

/**
 * A write filter that holds output until a newline, then passes each complete line on as its own
 * write.
 *
 * Forked workers share their parent's stdout instead of each having a pipe to a supervisor, and
 * `queue:work` writes every job line in three or four pieces. Without this, pieces from different
 * workers interleave mid-line. A pipe delivers a write of up to PIPE_BUF (4096 bytes on Linux) in one
 * piece, so a line written whole reaches the log whole.
 *
 * A partial line longer than MAX_HELD is passed on rather than held without bound, and whatever is
 * held when the stream closes is passed on then.
 */
final class LineBufferFilter extends php_user_filter
{
    public const NAME = 'queue-prefork.line-buffer';

    /** The most a partial line is held before it's passed on as is: PIPE_BUF on Linux. */
    public const MAX_HELD = 4096;

    private string $held = '';

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->held .= $bucket->data;
            $consumed += $bucket->datalen;
        }

        $passed = false;

        while (($end = strpos($this->held, "\n")) !== false) {
            $this->pass($out, substr($this->held, 0, $end + 1));
            $this->held = (string) substr($this->held, $end + 1);
            $passed = true;
        }

        if ($this->held !== '' && ($closing || strlen($this->held) >= self::MAX_HELD)) {
            $this->pass($out, $this->held);
            $this->held = '';
            $passed = true;
        }

        return $passed ? PSFS_PASS_ON : PSFS_FEED_ME;
    }

    /** One bucket per line: the stream writes each bucket with its own write call. */
    private function pass($out, string $data): void
    {
        stream_bucket_append($out, stream_bucket_new($this->stream, $data));
    }
}
