<?php

namespace Tevkert\QueuePrefork\Support;

use Throwable;

/**
 * The package's settings before Laravel boots, as Laravel will resolve them: from the cached
 * configuration when there is one (`php artisan config:cache`), else from the environment and the env
 * file, the way the package's config file reads them with env(). Dependency-free.
 */
final class EarlyConfig
{
    /** @var array<string, array{0: array, 1: bool}|null> release => [the cache's `queue-prefork` section, whether finding it loaded the env file] */
    private static array $cached = [];

    /**
     * @param  string  $key  the key in config/queue-prefork.php, in dot notation
     * @param  string  $env  the variable that key reads
     */
    public static function get(string $release, string $key, string $env): mixed
    {
        return self::resolve($release, $key, $env)[0];
    }

    /** Where get() finds the value: `cached config`, `environment`, or the env file's name. */
    public static function source(string $release, string $key, string $env): string
    {
        return self::resolve($release, $key, $env)[1];
    }

    /** @return array{0: mixed, 1: string} */
    private static function resolve(string $release, string $key, string $env): array
    {
        $cache = self::cachedConfig($release);

        if ($cache === null) {
            return [self::cast(DotEnv::get($release, $env)), DotEnv::source($release, $env)];
        }

        [$config, $envFileLoaded] = $cache;

        if (self::has($config, $key)) {
            return [self::dig($config, $key), 'cached config'];
        }

        // The cache lacks it (a boot without the package's provider): after boot the package's config
        // file still reads it with env(), which sees the environment, and the env file only when Laravel
        // loaded it to find this cache.
        if ($envFileLoaded) {
            return [self::cast(DotEnv::get($release, $env)), DotEnv::source($release, $env)];
        }

        $value = getenv($env);

        return [self::cast($value === false ? null : $value), 'environment'];
    }

    /** What Laravel's env() makes of a variable's text: true, false, '' and null for their words, quotes removed. */
    private static function cast(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => preg_match('/\A([\'"])(.*)\1\z/', $value, $m) ? $m[2] : $value,
        };
    }

    /** @return array{0: array, 1: bool}|null the cache's `queue-prefork` section and whether finding it loaded the env file */
    private static function cachedConfig(string $release): ?array
    {
        if (! array_key_exists($release, self::$cached)) {
            [$file, $envFileLoaded] = self::cachePath($release);
            $config = $file !== null ? self::load($file) : null;
            self::$cached[$release] = $config !== null ? [$config, $envFileLoaded] : null;
        }

        return self::$cached[$release];
    }

    private static function load(string $file): ?array
    {
        try {
            $config = (static fn (): mixed => require $file)();
        } catch (Throwable) {
            return null;
        }

        return is_array($config) ? (array) ($config['queue-prefork'] ?? []) : null;
    }

    /**
     * The cached config file, found as Laravel finds it: APP_CONFIG_CACHE from the environment, else
     * bootstrap/cache/config.php; when that isn't there, Laravel loads the env file and looks again, so
     * an APP_CONFIG_CACHE set there counts too.
     *
     * @return array{0: ?string, 1: bool} the file (null when the config isn't cached), and whether Laravel loaded the env file first
     */
    private static function cachePath(string $release): array
    {
        foreach ([getenv('APP_CONFIG_CACHE') ?: null, DotEnv::get($release, 'APP_CONFIG_CACHE')] as $i => $path) {
            $file = match (true) {
                $path === null || $path === '' => $i === 0 ? "{$release}/bootstrap/cache/config.php" : null,
                str_starts_with($path, '/') => $path,
                default => "{$release}/{$path}",
            };

            if ($file !== null && is_file($file)) {
                return [$file, $i === 1];
            }
        }

        return [null, false];
    }

    private static function has(array $config, string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            if (! is_array($config) || ! array_key_exists($segment, $config)) {
                return false;
            }

            $config = $config[$segment];
        }

        return true;
    }

    private static function dig(array $config, string $key): mixed
    {
        foreach (explode('.', $key) as $segment) {
            $config = $config[$segment];
        }

        return $config;
    }
}
