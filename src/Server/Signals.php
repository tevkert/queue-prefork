<?php

namespace Tevkert\QueuePrefork\Server;

use Tevkert\QueuePrefork\Support\Process;

/** Flag-only signal handling for the servers: handlers record, the main loop acts. */
final class Signals
{
    /**
     * The signals whose inherited "ignore" (nohup, a background job) a forked process keeps, by name.
     * Not SIGQUIT: probing it would dump core, and nothing but an explicit kill sends it to a
     * background process.
     */
    public const IGNORABLE = [SIGHUP => 'HUP', SIGINT => 'INT', SIGUSR1 => 'USR1', SIGUSR2 => 'USR2', SIGTERM => 'TERM'];

    /** @var array<int, int> signal => count */
    private static array $pending = [];

    /** @param list<int> $relay signals to record; @param list<int> $ignore */
    public static function install(array $record, array $ignore): void
    {
        pcntl_async_signals(false);

        foreach ($ignore as $signal) {
            pcntl_signal($signal, SIG_IGN);
        }

        foreach ($record as $signal) {
            pcntl_signal($signal, static function (int $signo): void {
                self::$pending[$signo] = (self::$pending[$signo] ?? 0) + 1;
            });
        }
    }

    /** @return array<int, int> */
    public static function take(): array
    {
        pcntl_signal_dispatch();
        $pending = self::$pending;
        self::$pending = [];

        return $pending;
    }

    /**
     * Which of the IGNORABLE signals this PHP process ignores. PHP catches the signals it manages and
     * ignores the ones it inherited as ignored (nohup) itself, so /proc shows them as caught and an exec
     * would reset them: only a forked child that signals itself tells. A signal with a PHP handler
     * isn't ignored (the application replaces or keeps that handler as it boots).
     *
     * @return list<int>
     */
    public static function ignored(): array
    {
        $ignored = [];

        foreach (array_keys(self::IGNORABLE) as $signal) {
            $handler = pcntl_signal_get_handler($signal);

            if ($handler !== SIG_DFL) {
                if ($handler === SIG_IGN) {
                    $ignored[] = $signal;
                }

                continue;
            }

            $pid = Process::fork();

            if ($pid === 0) {
                posix_kill(getmypid(), $signal);
                posix_kill(getmypid(), SIGKILL); // still alive: ignored. Never runs this process's shutdown code.
            }

            if ($pid > 0 && pcntl_waitpid($pid, $status) === $pid && pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL) {
                $ignored[] = $signal;
            }
        }

        return $ignored;
    }

    /** @return list<int> the signals set in a /proc/<pid>/status mask (hex, bit n-1 for signal n) */
    public static function inMask(string $mask): array
    {
        $mask = str_pad(strtolower($mask), 16, '0', STR_PAD_LEFT);
        $signals = [];

        for ($signo = 1; $signo <= 64; $signo++) {
            if (((int) hexdec($mask[15 - intdiv($signo - 1, 4)]) >> (($signo - 1) % 4)) & 1) {
                $signals[] = $signo;
            }
        }

        return $signals;
    }

    /** Status in the shell convention: exit code, or 128 + signal. */
    public static function status(int $raw): int
    {
        if (pcntl_wifexited($raw)) {
            return pcntl_wexitstatus($raw);
        }

        if (pcntl_wifsignaled($raw)) {
            return 128 + pcntl_wtermsig($raw);
        }

        return 255;
    }
}
