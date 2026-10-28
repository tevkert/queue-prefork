<?php

namespace Tevkert\QueuePrefork\Support;

/**
 * An environment variable before Laravel boots: the process environment's value, else the one in the
 * env file Laravel would load for this process. Dependency-free.
 *
 * That file is `.env.<environment>` for `--env=<environment>` or APP_ENV when it exists, else `.env`.
 * A custom environment path (loadEnvironmentFrom()) isn't followed.
 */
final class DotEnv
{
    public static function get(string $release, string $name): ?string
    {
        $value = getenv($name);

        return $value !== false ? $value : (self::values($release)[$name] ?? null);
    }

    /** Where get() finds the variable: `environment`, or the env file's name. */
    public static function source(string $release, string $name): string
    {
        return getenv($name) !== false ? 'environment' : basename(self::file($release));
    }

    /** @return array<string, string> the env file's variables (the last line wins, as in Dotenv) */
    private static function values(string $release): array
    {
        $contents = (string) @file_get_contents(self::file($release));
        $pattern = '/^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=[ \t]*(?:"([^"]*)"|\'([^\']*)\'|([^\s#]*))/m';
        preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER);
        $values = [];

        foreach ($matches as $match) {
            $values[$match[1]] = $match[2] !== '' ? $match[2] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
        }

        return $values;
    }

    private static function file(string $release): string
    {
        foreach ([self::environmentOption(), getenv('APP_ENV') ?: null] as $environment) {
            if ($environment !== null && $environment !== '' && is_file("{$release}/.env.{$environment}")) {
                return "{$release}/.env.{$environment}";
            }
        }

        return "{$release}/.env";
    }

    /** The command's `--env` option, as Artisan reads it. */
    private static function environmentOption(): ?string
    {
        $tokens = array_slice($_SERVER['argv'] ?? [], 1);

        foreach ($tokens as $i => $token) {
            if ($token === '--') {
                break;
            }

            if (str_starts_with((string) $token, '--env=')) {
                return substr((string) $token, 6);
            }

            if ($token === '--env') {
                return isset($tokens[$i + 1]) ? (string) $tokens[$i + 1] : null;
            }
        }

        return null;
    }
}
