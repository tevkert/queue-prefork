<?php

namespace Tevkert\QueuePrefork\Server;

use RuntimeException;
use Tevkert\QueuePrefork\Support\CommandLine;
use Tevkert\QueuePrefork\Support\Identity;

/**
 * What a `queue:work` stub's process is, so its forked worker can be the same: the command line,
 * environment, cwd and prepend, and the umask, limits, nice and signal state a stock exec would inherit
 * (the ignored signals PHP hides from /proc come from the stub's own probe).
 *
 * On Linux it is read from the verified process itself, never from what the stub says.
 */
final class WorkerContext
{
    /** posix_getrlimit() names => POSIX_RLIMIT_* constants. */
    private const RESOURCES = [
        'core' => 'POSIX_RLIMIT_CORE', 'data' => 'POSIX_RLIMIT_DATA', 'stack' => 'POSIX_RLIMIT_STACK',
        'totalmem' => 'POSIX_RLIMIT_AS', 'rss' => 'POSIX_RLIMIT_RSS', 'maxproc' => 'POSIX_RLIMIT_NPROC',
        'memlock' => 'POSIX_RLIMIT_MEMLOCK', 'cpu' => 'POSIX_RLIMIT_CPU', 'openfiles' => 'POSIX_RLIMIT_NOFILE',
        'filesize' => 'POSIX_RLIMIT_FSIZE',
    ];

    /** /proc/<pid>/limits labels => posix_getrlimit() names. */
    private const PROC_LIMITS = [
        'Max cpu time' => 'cpu', 'Max file size' => 'filesize', 'Max data size' => 'data', 'Max stack size' => 'stack',
        'Max core file size' => 'core', 'Max resident set' => 'rss', 'Max processes' => 'maxproc',
        'Max open files' => 'openfiles', 'Max locked memory' => 'memlock', 'Max address space' => 'totalmem',
    ];

    /**
     * Linux: argv, environment, cwd and the rest from /proc, so a client can't inject anything. The peer
     * must run this PHP binary, in this template's cgroup and namespaces.
     *
     * @return array<string, mixed>
     */
    public static function fromProc(int $pid, array $request, string $binary, string $release): array
    {
        $exe = @readlink("/proc/{$pid}/exe");

        if ($exe === false || (realpath($exe) ?: $exe) !== $binary) {
            throw new RuntimeException('the peer is not this PHP CLI binary');
        }

        foreach (['cgroup', 'ns/pid', 'ns/mnt'] as $what) {
            $theirs = $what === 'cgroup' ? @file_get_contents("/proc/{$pid}/cgroup") : @readlink("/proc/{$pid}/{$what}");
            $mine = $what === 'cgroup' ? @file_get_contents('/proc/self/cgroup') : @readlink("/proc/self/{$what}");

            if ($theirs !== $mine) {
                throw new RuntimeException("the peer's {$what} differs");
            }
        }

        $argv = explode("\0", rtrim((string) @file_get_contents("/proc/{$pid}/cmdline"), "\0"));
        $environ = @file_get_contents("/proc/{$pid}/environ");
        $status = @file_get_contents("/proc/{$pid}/status");
        $limits = @file_get_contents("/proc/{$pid}/limits");
        $stat = @file_get_contents("/proc/{$pid}/stat");
        $cwd = @readlink("/proc/{$pid}/cwd");

        if ($cwd === false || $argv === [''] || $environ === false || $status === false || $limits === false || $stat === false) {
            throw new RuntimeException('the peer is gone, or its /proc entries are unreadable');
        }

        $env = [];

        foreach (explode("\0", rtrim($environ, "\0")) as $pair) {
            if (($eq = strpos($pair, '=')) > 0) {
                $env[substr($pair, 0, $eq)] = substr($pair, $eq + 1);
            }
        }

        $fields = explode(' ', substr($stat, (int) strrpos($stat, ')') + 2));
        $request['umask'] = preg_match('/^Umask:\s+([0-7]+)/m', $status, $m) ? (int) octdec($m[1]) : null;
        $request['limits'] = self::procLimits($limits);
        $request['nice'] = isset($fields[16]) ? (int) $fields[16] : null;
        $request['sigign'] = preg_match('/^SigIgn:\s+([0-9a-f]+)/m', $status, $m) ? $m[1] : null;
        $request['sigblk'] = preg_match('/^SigBlk:\s+([0-9a-f]+)/m', $status, $m) ? $m[1] : null;

        return self::build($pid, $argv, $env, $cwd, $request, $release);
    }

    /**
     * macOS (development): the kernel verified the peer pid; the rest comes from its message.
     *
     * @return array<string, mixed>
     */
    public static function fromMessage(array $request, string $release): array
    {
        return self::build(
            (int) $request['pid'],
            array_map('strval', (array) ($request['argv'] ?? [])),
            array_map('strval', (array) ($request['env'] ?? [])),
            (string) ($request['cwd'] ?? ''),
            $request,
            $release,
        );
    }

    /**
     * In the forked worker, after admission: its own process group, then the stub's signal state,
     * umask, limits and nice.
     */
    public static function apply(array $context): void
    {
        @posix_setpgid(0, 0);

        if ($context['sigign'] !== null) {
            self::applyIgnoredSignals($context['sigign']);
        }

        foreach ($context['ignore'] as $signal) {
            pcntl_signal($signal, SIG_IGN); // PHP catches these itself, so they aren't in the stub's mask
        }

        if ($context['sigblk'] !== null) {
            pcntl_sigprocmask(SIG_SETMASK, array_values(array_diff(Signals::inMask($context['sigblk']), [SIGKILL, SIGSTOP, 32, 33])));
        }

        if ($context['env'] === []) {
            // A requester started with an empty environment (`env -i`): don't leave it the template's.
            foreach (array_keys(getenv()) as $name) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            }
        }

        if ($context['umask'] !== null) {
            umask($context['umask']);
        }

        foreach (self::softLimits($context['limits']) as [$resource, $soft]) {
            $current = posix_getrlimit();
            @posix_setrlimit($resource, $soft, self::limitValue($current['hard '.self::limitName($resource)] ?? 'unlimited'));
        }

        if ($context['nice'] !== null && function_exists('pcntl_setpriority') && $context['nice'] !== @pcntl_getpriority()) {
            @pcntl_setpriority($context['nice']);
        }
    }

    /**
     * @param  list<string>  $argv  the full command line: php, its options, the script, the command, …
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    private static function build(int $pid, array $argv, array $env, string $cwd, array $request, string $release): array
    {
        $command = CommandLine::fromProcess($argv);
        $entry = realpath($command->entry($cwd));

        if ($entry === false || dirname($entry) !== $release) {
            throw new RuntimeException('the entry script is not in this template\'s release');
        }

        $start = Identity::startTime($pid);

        if ($start === null) {
            throw new RuntimeException('the peer is gone');
        }

        return [
            'id' => preg_replace('/[^0-9a-f]/', '', (string) ($request['id'] ?? '')) ?: bin2hex(random_bytes(8)),
            'pid' => $pid,
            'start' => $start,
            'command' => $command,
            // The script as validated: a `current` symlink switched to the next release meanwhile
            // must not change what this release's template runs (argv keeps the spelling).
            'entry' => $entry,
            'env' => $env,
            'cwd' => $cwd,
            'prepend' => (string) ($request['prepend'] ?? ''),
            'umask' => isset($request['umask']) ? (int) $request['umask'] : null,
            'limits' => (array) ($request['limits'] ?? []),
            'nice' => isset($request['nice']) ? (int) $request['nice'] : null,
            'sigign' => isset($request['sigign']) ? (string) $request['sigign'] : null,
            'sigblk' => isset($request['sigblk']) ? (string) $request['sigblk'] : null,
            'ignore' => self::ignorable((array) ($request['ignored'] ?? [])),
            'stdio' => array_values(array_filter((array) ($request['stdio'] ?? []), 'is_array')),
        ];
    }

    /** @return list<int> the signals the stub's probe found ignored, of those it may report */
    private static function ignorable(array $signals): array
    {
        return array_values(array_intersect(array_keys(Signals::IGNORABLE), array_map('intval', $signals)));
    }

    /**
     * The requester's ignored signals, as its exec would have kept them (`SigIgn` from its
     * /proc/<pid>/status): an uncaught signal is ignored here exactly when it is there. Signals with a
     * handler (PHP's own SIGPROF for max_execution_time, an extension's) are left alone, as the
     * requester's own PHP would have installed them too.
     */
    private static function applyIgnoredSignals(string $mask): void
    {
        $own = (string) @file_get_contents('/proc/self/status');
        $caught = Signals::inMask(preg_match('/^SigCgt:\s+([0-9a-f]+)/m', $own, $m) ? $m[1] : '0');
        $ignored = Signals::inMask(preg_match('/^SigIgn:\s+([0-9a-f]+)/m', $own, $m) ? $m[1] : '0');
        $wanted = Signals::inMask($mask);

        for ($signo = 1; $signo <= 64; $signo++) {
            if (in_array($signo, [SIGKILL, SIGSTOP, 32, 33], true) || in_array($signo, $caught, true)) {
                continue; // 32 and 33 are glibc's own (pcntl_signal fails fatally on them)
            }

            if (in_array($signo, $wanted, true) !== in_array($signo, $ignored, true)) {
                pcntl_signal($signo, in_array($signo, $wanted, true) ? SIG_IGN : SIG_DFL);
            }
        }
    }

    /**
     * /proc/<pid>/limits as posix_getrlimit() names them ("soft openfiles" => 1024, ...).
     *
     * @return array<string, int|string>
     */
    private static function procLimits(string $limits): array
    {
        $out = [];

        foreach (explode("\n", $limits) as $line) {
            foreach (self::PROC_LIMITS as $label => $name) {
                if (str_starts_with($line, $label.' ') && preg_match('/^.{26}(\S+)\s+(\S+)/', $line, $m)) {
                    $out["soft {$name}"] = $m[1] === 'unlimited' ? 'unlimited' : (int) $m[1];
                    $out["hard {$name}"] = $m[2] === 'unlimited' ? 'unlimited' : (int) $m[2];
                }
            }
        }

        return $out;
    }

    /** @return list<array{0: int, 1: int}> [posix resource, soft value] for the requester's limits */
    private static function softLimits(array $limits): array
    {
        $out = [];

        foreach (self::RESOURCES as $name => $constant) {
            if (defined($constant) && array_key_exists("soft {$name}", $limits)) {
                $out[] = [constant($constant), self::limitValue($limits["soft {$name}"])];
            }
        }

        return $out;
    }

    private static function limitName(int $resource): string
    {
        foreach (self::RESOURCES as $name => $constant) {
            if (defined($constant) && constant($constant) === $resource) {
                return $name;
            }
        }

        return '';
    }

    private static function limitValue(int|string $value): int
    {
        return $value === 'unlimited' ? (defined('POSIX_RLIMIT_INFINITY') ? POSIX_RLIMIT_INFINITY : -1) : (int) $value;
    }
}
