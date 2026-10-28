<?php

namespace Tevkert\QueuePrefork\Console;

use Illuminate\Console\Command;
use Laravel\Horizon\MasterSupervisor;
use Tevkert\QueuePrefork\Settings;
use Tevkert\QueuePrefork\Support\Switches;

/**
 * The runtime switch: works whatever the config cache holds. With Horizon it lives on Horizon's
 * Redis connection (this host, or --all), for the package or one mode; without Horizon it is this
 * host's pre-boot off file for queue:work, storage/framework/queue-prefork.off-queue-work.
 */
class SwitchCommand extends Command
{
    protected $signature = 'queue-prefork:switch
        {action=show : enable, disable, clear or show}
        {--mode= : queue-work or horizon; both when omitted}
        {--all : For every host}';

    protected $description = 'Enable or disable queue-prefork at runtime (takes effect at the next start)';

    private const MODES = ['horizon' => 'Horizon', 'queue-work' => 'queue:work'];

    public function handle(): int
    {
        $mode = $this->option('mode');

        if ($mode !== null && ! isset(self::MODES[$mode])) {
            $this->components->error('--mode must be queue-work or horizon.');

            return self::FAILURE;
        }

        if (! class_exists(MasterSupervisor::class)) {
            return $this->offFile($mode);
        }

        $action = $this->argument('action');
        $target = $mode === null ? 'queue-prefork' : 'queue-prefork for '.self::MODES[$mode];

        if (in_array($action, ['enable', 'disable'], true)) {
            Settings::setSwitch($action, (bool) $this->option('all'), $mode);
            $this->components->info("{$target} {$action}d. Run `php artisan horizon:terminate` or `queue:restart` to apply it.");
        } elseif ($action === 'clear') {
            Settings::setSwitch(null, (bool) $this->option('all'), $mode);
            $this->components->info("Runtime switch for {$target} cleared; the config decides.");
        }

        foreach (self::MODES as $name => $label) {
            $off = Switches::off(base_path(), $name);
            $this->components->twoColumnDetail($label, $off !== []
                ? 'disabled ('.implode('; ', $off).')'
                : (Settings::enabled($this->laravel, $name) ? 'enabled' : 'disabled').' (from '.Settings::switchSource($this->laravel, $name).')');
        }

        return self::SUCCESS;
    }

    /** Without Horizon there is no shared switch store: this host's off file, read before Laravel boots. */
    private function offFile(?string $mode): int
    {
        if ($mode === 'horizon') {
            $this->components->error('Horizon is not installed.');

            return self::FAILURE;
        }

        $file = storage_path('framework/queue-prefork.off-queue-work');
        $action = $this->argument('action');

        if ($this->option('all')) {
            $this->components->warn('--all needs Horizon\'s Redis switch: run this on each host instead.');
        }

        if ($action === 'disable') {
            touch($file);
            $this->components->info('queue-prefork disabled on this host. Run `php artisan queue:restart` to apply it.');
        } elseif (in_array($action, ['enable', 'clear'], true)) {
            @unlink($file);
            $this->components->info('Off file removed; the config decides. Run `php artisan queue:restart` to apply it.');
        }

        $off = Switches::off(base_path(), 'queue-work');
        $this->components->twoColumnDetail('queue:work', $off !== []
            ? 'disabled ('.implode('; ', $off).')'
            : (Settings::enabled($this->laravel, 'queue-work') ? 'enabled' : 'disabled').' (from config)');

        return self::SUCCESS;
    }
}
