<?php

namespace Tevkert\QueuePrefork\Support;

use RuntimeException;

/**
 * The secure per-user runtime directory. Dependency-free.
 *
 * It holds the launcher scripts the master copies there and the status files units write. Every use
 * re-checks it: a directory, not a symlink, owned by this euid, mode 0700. Anything else is refused,
 * because another local user could otherwise plant code or forge statuses.
 */
final class RuntimeDir
{
    public static function locate(string $basePath, string $host, string $secret): string
    {
        $id = substr(hash('sha256', $basePath."\0".$host."\0".$secret), 0, 16);
        $xdg = self::xdg();

        return $xdg !== null ? "{$xdg}/hp-{$id}" : rtrim(sys_get_temp_dir(), '/').'/hp-'.posix_geteuid()."-{$id}";
    }

    /**
     * The per-user directory of the shared `queue:work` templates: their sockets, pid
     * files, launch copies, logs and request files. Keyed by the euid only, so it can be found before
     * Laravel boots.
     */
    public static function shared(): string
    {
        return (self::xdg() ?? rtrim(sys_get_temp_dir(), '/')).'/qp-'.posix_geteuid();
    }

    /**
     * XDG_RUNTIME_DIR, resolved, if it is this user's own private directory and no other user can
     * replace it or any directory above it. Another user's (`su` without `-`, `sudo -E`) could swap a
     * checked subdirectory, or a symlink on the way, for their own between the check and its use.
     */
    private static function xdg(): ?string
    {
        $xdg = (string) getenv('XDG_RUNTIME_DIR');
        $path = $xdg !== '' ? realpath($xdg) : false;
        $stat = $path !== false ? @lstat($path) : false;

        if ($stat === false || ! self::isDirectory($stat) || $stat['uid'] !== posix_geteuid() || ($stat['mode'] & 0777) !== 0700) {
            return null;
        }

        for ($dir = dirname($path); ; $dir = dirname($dir)) {
            if (! self::isSafeAncestor($dir)) {
                return null;
            }

            if ($dir === '/') {
                return $path;
            }
        }
    }

    /** Owned by root or this user, and nobody else can rename its entries (not writable, or sticky). */
    private static function isSafeAncestor(string $dir): bool
    {
        $stat = @lstat($dir);

        return $stat !== false && self::isDirectory($stat)
            && in_array($stat['uid'], [0, posix_geteuid()], true)
            && (($stat['mode'] & 0022) === 0 || ($stat['mode'] & 01000) !== 0);
    }

    private static function isDirectory(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0040000;
    }

    /**
     * Where shared templates of this user may be: workers under supervisord or a systemd system unit
     * have no XDG_RUNTIME_DIR, while a login shell (a deploy script over SSH) has one.
     *
     * @return list<string>
     */
    public static function sharedCandidates(): array
    {
        return array_values(array_unique([self::shared(), rtrim(sys_get_temp_dir(), '/').'/qp-'.posix_geteuid()]));
    }

    public static function ensure(string $dir): void
    {
        if (@lstat($dir) === false) {
            $umask = umask(0077);

            try {
                if (! @mkdir($dir, 0700) && @lstat($dir) === false) {
                    throw new RuntimeException("Cannot create runtime directory {$dir}.");
                }
            } finally {
                umask($umask);
            }
        }

        self::check($dir);
    }

    public static function check(string $dir): void
    {
        $stat = @lstat($dir);

        if ($stat === false) {
            throw new RuntimeException("Runtime directory {$dir} is missing.");
        }

        if (is_link($dir) || ($stat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException("Runtime directory {$dir} is not a plain directory.");
        }

        if ($stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException("Runtime directory {$dir} is owned by uid {$stat['uid']}, not ".posix_geteuid().'.');
        }

        if (($stat['mode'] & 0777) !== 0700) {
            throw new RuntimeException(sprintf('Runtime directory %s has mode %o, expected 0700.', $dir, $stat['mode'] & 0777));
        }
    }

    public static function writeAtomic(string $dir, string $name, string $contents): void
    {
        self::check($dir);
        $tmp = "{$dir}/.{$name}.".getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($tmp, $contents) === false) {
            throw new RuntimeException("Cannot write {$tmp}.");
        }

        @chmod($tmp, 0600);

        if (! @rename($tmp, "{$dir}/{$name}")) {
            @unlink($tmp);

            throw new RuntimeException("Cannot rename {$tmp}.");
        }
    }

    /** A copy of a package file in $dir, named by its content, so pruning a release can't remove it. */
    public static function copy(string $dir, string $name, string $extension, string $contents): string
    {
        $file = "{$name}-".substr(hash('sha256', $contents), 0, 16).".{$extension}";

        if (! is_file("{$dir}/{$file}")) {
            self::writeAtomic($dir, $file, $contents);
        }

        return "{$dir}/{$file}";
    }

    /** Reads a file only if it is a regular file owned by this euid. */
    public static function read(string $dir, string $name): ?string
    {
        self::check($dir);
        $path = "{$dir}/{$name}";
        $stat = @lstat($path);

        if ($stat === false || is_link($path) || ($stat['mode'] & 0170000) !== 0100000 || $stat['uid'] !== posix_geteuid()) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /** Removes status directories whose master identity is dead (this host only). */
    public static function prune(string $dir): void
    {
        self::check($dir);

        foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $sub) {
            if (! preg_match('/\/(\d+)-([0-9A-Za-z]+)$/', $sub, $m)) {
                continue;
            }

            $start = Identity::startTime((int) $m[1]);

            if ($start !== null && preg_replace('/[^0-9A-Za-z]+/', '', $start) === $m[2]) {
                continue;
            }

            foreach (glob($sub.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($sub);
        }
    }
}
