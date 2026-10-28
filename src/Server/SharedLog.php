<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Support\Identity;
use Tevkert\QueuePrefork\Support\Process;

/**
 * Shared-log mode's output file, storage/logs/queue-prefork-<host>-<key16>.log: the stdout and stderr
 * of a template and all its workers. The template holds a lease on it while it runs, rotates it by
 * copy-then-truncate, and prunes old sets no live template owns.
 */
final class SharedLog
{
    private string $lease = '';

    public function __construct(
        private string $path,
        private float $maxBytes,
        private int $files,
    ) {}

    /** Held from launch until the last child is reaped, independent of the discovery pid file. */
    public function takeLease(): void
    {
        $owners = $this->path.'.owners';
        @mkdir($owners, 0700);
        $this->lease = $owners.'/'.getmypid().'-'.self::startKey(getmypid());
        @touch($this->lease);
    }

    public function releaseLease(): void
    {
        if ($this->lease !== '') {
            @unlink($this->lease);
        }
    }

    /**
     * Copy-then-truncate above the size limit: workers write in append mode, so they carry on at the
     * new end. The copy runs in a forked helper so the template's loop never blocks on it, and the
     * whole rotation holds an flock, so a retiring template and its successor never rotate one file
     * twice.
     *
     * @param  callable(): void  $detach  lets go of the template's connections in the helper
     */
    public function rotateIfNeeded(callable $detach): void
    {
        clearstatcache(true, $this->path);

        if (@filesize($this->path) < $this->maxBytes) {
            return;
        }

        $helper = Process::fork();

        if ($helper !== 0) {
            return; // the template's reap() collects it; a failed fork just rotates at the next check
        }

        $detach();

        foreach ([SIGTERM, SIGINT, SIGQUIT, SIGHUP, SIGALRM] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }

        pcntl_alarm(30); // a copy that hangs (a stalled disk) ends here
        $lock = @fopen($this->path.'.lock', 'c');

        if ($lock !== false && flock($lock, LOCK_EX | LOCK_NB)) {
            clearstatcache(true, $this->path);

            if (@filesize($this->path) >= $this->maxBytes) {
                $this->rotate();
            }

            flock($lock, LOCK_UN);
        }

        RoleGuard::shim(0); // the helper leaves without running any of the template's shutdown code
    }

    /**
     * Every deploy starts a new key and so a new log set. Delete this host's sets that no live template
     * owns, beyond the newest 3, and only after 24 h without a write.
     */
    public function pruneSets(): void
    {
        $prefix = substr($this->path, 0, (int) strrpos($this->path, '-') + 1); // …/queue-prefork-<host>-
        $sets = [];

        foreach (glob($prefix.'*.log') ?: [] as $log) {
            if (preg_match('/^[0-9a-f]{16}\.log$/', substr($log, strlen($prefix)))) { // not web-1-b's for web-1
                $sets[$log] = (int) @filemtime($log);
            }
        }

        arsort($sets);
        $rank = 0;

        foreach ($sets as $log => $mtime) {
            if ($rank++ < 3 || $log === $this->path || $mtime > time() - 86400 || self::owned($log)) {
                continue;
            }

            foreach ([...glob($log.'*') ?: [], ...glob($log.'.owners/*') ?: []] as $file) {
                @unlink($file);
            }

            @rmdir($log.'.owners');
        }
    }

    private function rotate(): void
    {
        for ($n = max(1, $this->files) - 1; $n >= 1; $n--) {
            @rename("{$this->path}.{$n}", $this->path.'.'.($n + 1));
        }

        if (@copy($this->path, $this->path.'.1') && ($handle = @fopen($this->path, 'r+')) !== false) {
            ftruncate($handle, 0);
            fclose($handle);
        }
    }

    /** Whether a live template holds a lease on this log set; dead owners' leases are removed on the way. */
    private static function owned(string $log): bool
    {
        foreach (glob($log.'.owners/*') ?: [] as $lease) {
            [$pid, $start] = array_pad(explode('-', basename($lease), 2), 2, '');

            if (self::startKey((int) $pid) === $start) {
                return true;
            }

            @unlink($lease);
        }

        return false;
    }

    private static function startKey(int $pid): string
    {
        return (string) preg_replace('/[^0-9A-Za-z]+/', '', (string) Identity::startTime($pid));
    }
}
