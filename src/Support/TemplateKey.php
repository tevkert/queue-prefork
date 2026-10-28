<?php

namespace Tevkert\QueuePrefork\Support;

use Tevkert\QueuePrefork\Runtime;

/**
 * Which shared template a `queue:work` process may fork from. Dependency-free; runs
 * in the stub before Laravel boots, and in the template to verify itself.
 *
 * Two processes get the same key only when a fork of one template is indistinguishable from a stock
 * start of the other: the same PHP build and startup ini, the same release, the same user, limits and
 * scheduling they can't change themselves, and (on Linux) the same cgroup and namespaces, so stopping
 * one systemd service never takes another's workers with it.
 */
final class TemplateKey
{
    /** ini settings the template forces for itself; excluded from the key. */
    public const FORCED_INI = [
        'auto_prepend_file',
        'opcache.enable',
        'opcache.enable_cli',
        'opcache.interned_strings_buffer',
        'opcache.jit',
        'opcache.max_accelerated_files',
        'opcache.memory_consumption',
        'opcache.preload',
        'opcache.revalidate_freq',
        'opcache.save_comments',
        'opcache.validate_timestamps',
    ];

    /** Template settings with their defaults; part of the key, so different settings never share a template. */
    public const SETTINGS = [
        'QUEUE_PREFORK_OPCACHE_MB' => '192',
        'QUEUE_PREFORK_INTERNED_MB' => '32',
        'QUEUE_PREFORK_MAX_FILES' => '20000',
        'QUEUE_PREFORK_ALLOW_DESCRIPTORS' => '',
        'QUEUE_PREFORK_THREAD_AUDIT' => '1',
        'QUEUE_PREFORK_KILL_GROUP' => '1',
        'QUEUE_PREFORK_LOG_MAX_MB' => '10',
        'QUEUE_PREFORK_LOG_FILES' => '3',
        'QUEUE_PREFORK_WARMUP' => '1',
    ];

    /** Each setting's key in config/queue-prefork.php. */
    public const CONFIG_KEYS = [
        'QUEUE_PREFORK_OPCACHE_MB' => 'opcache.memory_consumption',
        'QUEUE_PREFORK_INTERNED_MB' => 'opcache.interned_strings_buffer',
        'QUEUE_PREFORK_MAX_FILES' => 'opcache.max_accelerated_files',
        'QUEUE_PREFORK_ALLOW_DESCRIPTORS' => 'allow_descriptors',
        'QUEUE_PREFORK_THREAD_AUDIT' => 'thread_audit',
        'QUEUE_PREFORK_KILL_GROUP' => 'kill_group',
        'QUEUE_PREFORK_LOG_MAX_MB' => 'shared_log.max_mb',
        'QUEUE_PREFORK_LOG_FILES' => 'shared_log.files',
        'QUEUE_PREFORK_WARMUP' => 'warmup',
    ];

    /** Hard limits a forked child can't raise back, so they must match (posix_getrlimit names). */
    private const HARD_LIMITS = [
        'hard core',
        'hard filesize',
        'hard data',
        'hard stack',
        'hard totalmem',
        'hard rss',
        'hard maxproc',
        'hard memlock',
        'hard cpu',
        'hard openfiles',
    ];

    /**
     * @param  string  $release  realpath of the entry script's directory
     * @param  string  $output  'normal' or 'shared-log'
     * @return array{key: string, key16: string, ini: string, settings: array<string, string>}
     */
    public static function compute(string $release, string $output): array
    {
        $settings = self::settings($release);
        $binary = realpath(PHP_BINARY) ?: PHP_BINARY;
        $stat = @stat($binary) ?: [];
        $limits = function_exists('posix_getrlimit') ? (posix_getrlimit() ?: []) : [];
        $groups = function_exists('posix_getgroups') ? (posix_getgroups() ?: []) : [];
        sort($groups);
        $extensions = get_loaded_extensions();
        $zend = get_loaded_extensions(true);
        sort($extensions);
        sort($zend);

        $parts = [
            'protocol' => Runtime::PROTOCOL,
            'php' => [PHP_VERSION, $binary, $stat['ino'] ?? 0, $stat['mtime'] ?? 0],
            'extensions' => [$extensions, $zend],
            'ini' => self::iniHash(),
            'release' => $release,
            'output' => $output,
            'settings' => $settings,
            'user' => [posix_geteuid(), posix_getegid(), $groups],
            'limits' => array_intersect_key($limits, array_flip(self::HARD_LIMITS)),
            'nice' => function_exists('pcntl_getpriority') ? @pcntl_getpriority() : null,
            'linux' => PHP_OS_FAMILY === 'Linux' ? [
                (string) @file_get_contents('/proc/self/cgroup'),
                (string) @readlink('/proc/self/ns/pid'),
                (string) @readlink('/proc/self/ns/mnt'),
            ] : null,
        ];

        $key = hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES));

        return ['key' => $key, 'key16' => substr($key, 0, 16), 'ini' => $parts['ini'], 'settings' => $settings];
    }

    /** Hash of every startup ini value except the forced ones (global values: `ini_set` can't move it). */
    public static function iniHash(): string
    {
        return hash('sha256', json_encode(self::iniValues(), JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, string|null> */
    public static function iniValues(): array
    {
        $values = [];

        foreach (ini_get_all(null, true) as $name => $entry) {
            if (! in_array($name, self::FORCED_INI, true)) {
                $values[$name] = $entry['global_value'] ?? null;
            }
        }

        ksort($values);

        return $values;
    }

    /**
     * @param  array<string, string|null>  $theirs  another process's iniValues()
     * @return list<string> the first 20 ini names whose startup values differ from this process's
     */
    public static function iniDifferences(array $theirs): array
    {
        $mine = self::iniValues();
        $names = array_keys(array_diff_assoc($mine, $theirs) + array_diff_assoc($theirs, $mine));
        sort($names);

        return array_slice($names, 0, 20);
    }

    /** @return array<string, string> the release's template settings, as Laravel resolves them (EarlyConfig), defaults applied */
    public static function settings(string $release): array
    {
        $settings = [];

        foreach (self::SETTINGS as $name => $default) {
            $value = EarlyConfig::get($release, self::CONFIG_KEYS[$name], $name);
            $settings[$name] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => implode(',', $value),
                $value === null || $value === '' => $default,
                default => (string) $value,
            };
        }

        return $settings;
    }

    /** The `-d` options the template forces (Master.php keeps the same set for Horizon mode). */
    public static function forcedIni(array $settings): array
    {
        return [
            'opcache.enable=1', 'opcache.enable_cli=1', 'opcache.validate_timestamps=1', 'opcache.revalidate_freq=0',
            'opcache.save_comments=1', 'opcache.preload=', 'opcache.jit=disable',
            'opcache.memory_consumption='.(int) $settings['QUEUE_PREFORK_OPCACHE_MB'],
            'opcache.interned_strings_buffer='.(int) $settings['QUEUE_PREFORK_INTERNED_MB'],
            'opcache.max_accelerated_files='.(int) $settings['QUEUE_PREFORK_MAX_FILES'],
            'auto_prepend_file=',
        ];
    }
}
