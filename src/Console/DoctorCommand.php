<?php

namespace Tevkert\QueuePrefork\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\MasterSupervisor;
use Tevkert\QueuePrefork\Handoff;
use Tevkert\QueuePrefork\MasterHandoff;
use Tevkert\QueuePrefork\Preflight;
use Tevkert\QueuePrefork\Settings;
use Tevkert\QueuePrefork\Support\Switches;
use Tevkert\QueuePrefork\Support\TemplateKey;

/**
 * Readiness report: Horizon's preflight with probes when Horizon is installed,
 * how `queue:work` would run (with the FFI install line for this platform when it's missing), plus host
 * checks and legacy settings that only warn.
 */
class DoctorCommand extends Command
{
    protected $signature = 'queue-prefork:doctor {--json : Machine-readable output}';

    protected $description = 'Check whether this app and host can run its queue workers with queue-prefork';

    public function handle(): int
    {
        Settings::mergeDefaults($this->laravel);
        $horizon = class_exists(MasterSupervisor::class);
        $release = realpath(base_path()) ?: base_path();
        $reasons = $horizon ? [...Switches::off($release, 'horizon'), ...Preflight::check($this->laravel)] : [];
        $queueWork = Handoff::readiness($release);
        $queueWork['install'] = $queueWork['ffi'] !== null ? self::ffiInstall() : null;
        $warnings = [...self::hostWarnings($horizon), ...$this->earlyMismatches($release)];

        $hosting = $horizon ? MasterHandoff::reasons($release) : [];
        $result = [
            'ready' => $reasons === [] && $queueWork['mode'] !== null,
            'reasons' => $reasons,
            'horizon' => $horizon ? ($reasons === [] ? 'prefork' : 'stock') : 'not installed',
            'horizon_master' => $horizon && $reasons === [] ? ($hosting === [] ? 'forked from a host template' : 'spawns its template') : null,
            'horizon_master_reasons' => $hosting,
            'queue_work' => $queueWork,
            'warnings' => $warnings,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['ready'] ? 0 : 1;
        }

        $this->components->twoColumnDetail('Horizon', $result['horizon'].($result['horizon_master'] !== null ? ', master '.$result['horizon_master'] : ''));

        if ($reasons !== [] || ($result['horizon_master'] !== null && $hosting !== [])) {
            $this->components->bulletList($reasons !== [] ? $reasons : $hosting);
        }

        $this->components->twoColumnDetail('queue:work', match ($queueWork['mode']) {
            'normal' => 'prefork',
            'shared-log' => 'prefork, shared-log mode: worker output goes to storage/logs/queue-prefork-*.log',
            default => 'stock',
        });

        if ($queueWork['reasons'] !== [] || $queueWork['ffi'] !== null) {
            $this->components->bulletList([
                ...$queueWork['reasons'],
                ...($queueWork['ffi'] !== null ? ["FFI: {$queueWork['ffi']}", "Fix: {$queueWork['install']}"] : []),
            ]);
        }

        foreach ($warnings as $warning) {
            $this->components->warn($warning);
        }

        return $result['ready'] ? 0 : 1;
    }

    /** @return list<string> */
    private static function hostWarnings(bool $horizon): array
    {
        $warnings = [];
        $pidMax = (int) @file_get_contents('/proc/sys/kernel/pid_max');

        if ($pidMax > 0 && $pidMax < 4194304) {
            $warnings[] = "kernel.pid_max is {$pidMax}; 4194304 makes pid reuse on the emergency signal path even less likely";
        }

        if (extension_loaded('apcu') && ini_get('apc.enable_cli')) {
            $warnings[] = 'APCu with apc.enable_cli: one cache is shared by all forked workers (as under FPM)';
        }

        if ($horizon && str_contains(base_path(), 'horizon')) {
            $warnings[] = 'base path contains "horizon": the template relies on its instant retitle to stay clear of horizon:purge';
        }

        return $warnings;
    }

    /**
     * Settings the app's config gives differently from what the templates read before Laravel boots:
     * only possible with an uncached config that sets a value other than through its variable.
     *
     * @return list<string>
     */
    private function earlyMismatches(string $release): array
    {
        $warnings = [];
        $settings = TemplateKey::settings($release);

        foreach (TemplateKey::CONFIG_KEYS as $env => $key) {
            $value = $this->laravel['config']->get("queue-prefork.{$key}");
            $value = is_bool($value) ? ($value ? '1' : '0') : (is_array($value) ? implode(',', $value) : (string) $value);

            if ($value !== '' && $value !== $settings[$env]) {
                $warnings[] = "config queue-prefork.{$key} is {$value}, but the templates, which start before Laravel boots, "
                    ."read {$settings[$env]}: run php artisan config:cache, or set {$env}";
            }
        }

        return $warnings;
    }

    /** The FFI install line for this platform. */
    private static function ffiInstall(): string
    {
        if (extension_loaded('ffi')) {
            return 'set ffi.enable=preload (the default) or true for the CLI';
        }

        $version = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

        if (PHP_OS_FAMILY === 'Darwin') {
            return 'use a PHP build with FFI (Herd and Homebrew ship it enabled)';
        }

        if (is_file('/usr/local/bin/docker-php-ext-install')) {
            return is_file('/etc/alpine-release')
                ? 'apk add --no-cache libffi-dev && docker-php-ext-install ffi'
                : 'apt-get install -y libffi-dev && docker-php-ext-install ffi';
        }

        $os = @parse_ini_file('/etc/os-release') ?: [];
        $ids = strtolower(($os['ID'] ?? '').' '.($os['ID_LIKE'] ?? ''));

        return match (true) {
            str_contains($ids, 'alpine') => 'apk add php'.str_replace('.', '', $version).'-ffi',
            str_contains($ids, 'debian') || str_contains($ids, 'ubuntu') => "phpenmod -v {$version} -s cli ffi (php{$version}-common ships it)",
            (bool) preg_match('/rhel|fedora|centos/', $ids) => 'dnf install php-ffi',
            default => 'install or enable the PHP FFI extension for the CLI',
        };
    }
}
