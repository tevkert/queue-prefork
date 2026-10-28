<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Switches;
use Tevkert\QueuePrefork\Support\TemplateKey;

class SwitchesTest extends TestCase
{
    private const NAMES = [
        'QUEUE_PREFORK', 'QUEUE_PREFORK_QUEUE_WORK', 'QUEUE_PREFORK_HORIZON', 'QUEUE_PREFORK_OPCACHE_MB', 'QUEUE_PREFORK_KILL_GROUP',
        'APP_ENV', 'APP_CONFIG_CACHE',
    ];

    private array $argv = [];

    private string $release;

    protected function setUp(): void
    {
        $this->release = sys_get_temp_dir().'/qp-switches-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->release.'/storage/framework', 0700, true);

        foreach (self::NAMES as $name) {
            putenv($name);
        }

        $this->argv = $_SERVER['argv'];
        $_SERVER['argv'] = ['artisan', 'horizon'];
    }

    protected function tearDown(): void
    {
        $_SERVER['argv'] = $this->argv;

        foreach (self::NAMES as $name) {
            putenv($name);
        }

        exec('rm -rf '.escapeshellarg($this->release));
    }

    public function test_on_unless_something_switches_it_off(): void
    {
        file_put_contents($this->release.'/.env', "APP_NAME=x\nQUEUE_PREFORK=true\n");

        $this->assertSame([], Switches::off($this->release, 'horizon'));
        $this->assertSame([], Switches::off($this->release, 'queue-work'));
    }

    public function test_each_mode_has_its_own_switch_and_queue_prefork_turns_off_both(): void
    {
        putenv('QUEUE_PREFORK_HORIZON=false');
        $this->assertSame(['QUEUE_PREFORK_HORIZON is off (environment)'], Switches::off($this->release, 'horizon'));
        $this->assertSame([], Switches::off($this->release, 'queue-work'));

        putenv('QUEUE_PREFORK=0');
        $this->assertSame(['QUEUE_PREFORK is off (environment)'], Switches::off($this->release, 'queue-work'));
    }

    public function test_the_env_file_counts_and_the_process_environment_wins(): void
    {
        file_put_contents($this->release.'/.env', "QUEUE_PREFORK_QUEUE_WORK=true\n# QUEUE_PREFORK=false\nexport QUEUE_PREFORK_QUEUE_WORK=\"(false)\"\n");
        $this->assertSame(['QUEUE_PREFORK_QUEUE_WORK is off (.env)'], Switches::off($this->release, 'queue-work'), 'the last line wins; comments don\'t count');

        putenv('QUEUE_PREFORK_QUEUE_WORK=true');
        $this->assertSame([], Switches::off($this->release, 'queue-work'));
    }

    public function test_the_env_file_is_the_one_laravel_would_load(): void
    {
        file_put_contents($this->release.'/.env', "QUEUE_PREFORK_HORIZON=true\n");
        file_put_contents($this->release.'/.env.production', "QUEUE_PREFORK_HORIZON=false\n");
        file_put_contents($this->release.'/.env.staging', "QUEUE_PREFORK=0\n");

        putenv('APP_ENV=production');
        $this->assertSame(['QUEUE_PREFORK_HORIZON is off (.env.production)'], Switches::off($this->release, 'horizon'));

        $_SERVER['argv'] = ['artisan', 'horizon', '--env=staging'];
        $this->assertSame(['QUEUE_PREFORK is off (.env.staging)'], Switches::off($this->release, 'horizon'), '--env wins over APP_ENV');

        putenv('APP_ENV=local'); // no .env.local: .env
        $_SERVER['argv'] = ['artisan', 'horizon'];
        $this->assertSame([], Switches::off($this->release, 'horizon'));
    }

    public function test_template_settings_come_from_the_environment_or_the_env_file(): void
    {
        file_put_contents($this->release.'/.env', "QUEUE_PREFORK_OPCACHE_MB=\"256\"\n");
        $this->assertSame('256', TemplateKey::settings($this->release)['QUEUE_PREFORK_OPCACHE_MB']);

        putenv('QUEUE_PREFORK_OPCACHE_MB=128');
        $this->assertSame('128', TemplateKey::settings($this->release)['QUEUE_PREFORK_OPCACHE_MB']);
        $this->assertSame('32', TemplateKey::settings($this->release)['QUEUE_PREFORK_INTERNED_MB'], 'defaults');
    }

    public function test_a_cached_config_wins_as_it_does_in_laravel(): void
    {
        mkdir($this->release.'/bootstrap/cache', 0700, true);
        $this->cacheConfig($this->release.'/bootstrap/cache/config.php', [
            'enabled' => true,
            'horizon' => false,
            'opcache' => ['memory_consumption' => 256],
            'thread_audit' => false,
            'allow_descriptors' => ['socket:*', 'pipe:*'],
        ]);
        putenv('QUEUE_PREFORK_HORIZON=true'); // not read: the config is cached
        putenv('QUEUE_PREFORK_OPCACHE_MB=128');

        $this->assertSame(['QUEUE_PREFORK_HORIZON is off (cached config)'], Switches::off($this->release, 'horizon'));
        $this->assertSame([], Switches::off($this->release, 'queue-work'));

        $settings = TemplateKey::settings($this->release);
        $this->assertSame('256', $settings['QUEUE_PREFORK_OPCACHE_MB']);
        $this->assertSame('0', $settings['QUEUE_PREFORK_THREAD_AUDIT']);
        $this->assertSame('socket:*,pipe:*', $settings['QUEUE_PREFORK_ALLOW_DESCRIPTORS']);
        $this->assertSame('32', $settings['QUEUE_PREFORK_INTERNED_MB'], 'a key the cache lacks: its default');
    }

    public function test_app_config_cache_moves_the_cached_config(): void
    {
        $this->cacheConfig($this->release.'/custom-config.php', ['enabled' => false]);
        putenv('APP_CONFIG_CACHE=custom-config.php'); // relative to the base path, as in Laravel

        $this->assertSame(['QUEUE_PREFORK is off (cached config)'], Switches::off($this->release, 'queue-work'));
    }

    public function test_values_mean_what_laravels_env_and_bool_make_of_them(): void
    {
        file_put_contents($this->release.'/.env', "QUEUE_PREFORK_KILL_GROUP=false\nQUEUE_PREFORK_HORIZON=off\nQUEUE_PREFORK_QUEUE_WORK=(false)\n");

        $this->assertSame('0', TemplateKey::settings($this->release)['QUEUE_PREFORK_KILL_GROUP'], 'false, as a cached config would hold it');
        $this->assertSame([], Switches::off($this->release, 'horizon'), "'off' is a true string to Laravel, as after boot");
        $this->assertSame(['QUEUE_PREFORK_QUEUE_WORK is off (.env)'], Switches::off($this->release, 'queue-work'));
    }

    public function test_a_cache_without_the_package_section_reads_the_environment_as_its_config_file_would(): void
    {
        mkdir($this->release.'/bootstrap/cache', 0700, true);
        file_put_contents($this->release.'/bootstrap/cache/config.php', '<?php return '.var_export(['app' => ['name' => 'x']], true).';');
        file_put_contents($this->release.'/.env', "QUEUE_PREFORK_HORIZON=false\n"); // not loaded under a cached config
        putenv('QUEUE_PREFORK=false');

        $this->assertSame(['QUEUE_PREFORK is off (environment)'], Switches::off($this->release, 'horizon'));
    }

    public function test_an_app_config_cache_set_in_the_env_file_counts_when_the_default_cache_is_missing(): void
    {
        $this->cacheConfig($this->release.'/custom-config.php', ['enabled' => true, 'kill_group' => false]);
        file_put_contents($this->release.'/.env', "APP_CONFIG_CACHE=custom-config.php\nQUEUE_PREFORK_KILL_GROUP=true\n");

        $this->assertSame('0', TemplateKey::settings($this->release)['QUEUE_PREFORK_KILL_GROUP']);
    }

    public function test_a_key_missing_from_a_cache_found_through_the_env_file_reads_the_env_file_too(): void
    {
        $this->cacheConfig($this->release.'/custom-config.php', ['enabled' => true]); // no kill_group
        file_put_contents($this->release.'/.env', "APP_CONFIG_CACHE=custom-config.php\nQUEUE_PREFORK_KILL_GROUP=false\n");

        $this->assertSame('0', TemplateKey::settings($this->release)['QUEUE_PREFORK_KILL_GROUP'], 'Laravel loaded .env to find this cache');
    }

    public function test_off_files_for_this_host(): void
    {
        touch($this->release.'/storage/framework/queue-prefork.off-horizon');
        $this->assertSame(['storage/framework/queue-prefork.off-horizon exists'], Switches::off($this->release, 'horizon'));
        $this->assertSame([], Switches::off($this->release, 'queue-work'));

        touch($this->release.'/storage/framework/queue-prefork.off');
        $this->assertSame(['storage/framework/queue-prefork.off exists'], Switches::off($this->release, 'queue-work'));
    }

    private function cacheConfig(string $file, array $section): void
    {
        file_put_contents($file, '<?php return '.var_export(['app' => ['name' => 'x'], 'queue-prefork' => $section], true).';'.PHP_EOL);
    }
}
