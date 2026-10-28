<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\LogTargets;

/** The stdio-logging check of shared-log mode, with stand-ins for Monolog's handlers. */
class LogTargetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/qp-logtargets-'.getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    public function test_php_stdio_urls_count_by_definition(): void
    {
        foreach (['php://stdout', 'php://stderr', 'php://output', 'php://fd/2'] as $url) {
            $this->assertTrue(LogTargets::anyStdio([new FakeStreamHandler($url)], []), $url);
        }

        $this->assertFalse(LogTargets::anyStdio([new FakeStreamHandler($this->dir.'/laravel.log')], []));
    }

    public function test_a_symlink_to_the_stubs_output_counts_by_identity(): void
    {
        $stubOut = $this->dir.'/stub-out';
        touch($stubOut);
        symlink($stubOut, $this->dir.'/laravel.log'); // like `ln -sf /dev/stdout storage/logs/laravel.log`
        $id = [stat($stubOut)['dev'], stat($stubOut)['ino']];

        $this->assertTrue(LogTargets::anyStdio([new FakeStreamHandler($this->dir.'/laravel.log')], [$id]));
        $this->assertFalse(LogTargets::anyStdio([new FakeStreamHandler($this->dir.'/other.log')], [$id]));
    }

    public function test_dev_stderr_resolves_through_this_processs_own_descriptor(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('/dev/stderr resolves through /proc on Linux; shared-log mode is Linux-only');
        }

        $this->assertTrue(LogTargets::anyStdio([new FakeStreamHandler('/dev/stderr')], LogTargets::identities([])));
    }

    public function test_an_open_stream_is_compared_by_its_descriptor(): void
    {
        $file = $this->dir.'/open.log';
        $handler = new FakeStreamHandler($file, fopen($file, 'a'));
        $id = [stat($file)['dev'], stat($file)['ino']];

        $this->assertTrue(LogTargets::anyStdio([$handler], [$id]));
    }

    public function test_error_log_counts_only_without_an_error_log_file(): void
    {
        $old = ini_set('error_log', '');
        $this->assertTrue(LogTargets::anyStdio([new FakeErrorLogHandler], []));
        ini_set('error_log', $this->dir.'/php-errors.log');
        $this->assertFalse(LogTargets::anyStdio([new FakeErrorLogHandler], []));
        ini_set('error_log', (string) $old);
    }

    public function test_wrappers_and_groups_are_followed(): void
    {
        $stderr = new FakeStreamHandler('php://stderr');
        $file = new FakeStreamHandler($this->dir.'/laravel.log');

        $this->assertTrue(LogTargets::anyStdio([new FakeFingersCrossedHandler($stderr)], []), 'getHandler()');
        $this->assertTrue(LogTargets::anyStdio([new FakeBufferHandler($stderr)], []), 'a protected $handler');
        $this->assertTrue(LogTargets::anyStdio([new FakeGroupHandler([$file, new FakeBufferHandler($stderr)])], []), 'a group, nested');
        $this->assertFalse(LogTargets::anyStdio([new FakeGroupHandler([$file, new FakeBufferHandler($file)])], []));
        $this->assertFalse(LogTargets::anyStdio([new FakeFingersCrossedHandler(fn () => $stderr)], []), 'an unresolved factory is not guessed at');
    }

    public function test_the_default_channel_is_read_through_the_app(): void
    {
        $app = ['log' => new FakeLogManager([new FakeStreamHandler('php://stderr')])];
        $this->assertTrue(LogTargets::defaultChannelWritesToStdio(new \ArrayObject($app), []));

        $app = ['log' => new FakeLogManager([new FakeStreamHandler($this->dir.'/laravel.log')])];
        $this->assertFalse(LogTargets::defaultChannelWritesToStdio(new \ArrayObject($app), []));
    }
}

class FakeStreamHandler
{
    public function __construct(private ?string $url, private $stream = null) {}

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function getStream()
    {
        return $this->stream;
    }
}

class FakeErrorLogHandler {}

class FakeFingersCrossedHandler
{
    public function __construct(private $inner) {}

    public function getHandler(): ?object
    {
        return $this->inner instanceof \Closure ? null : $this->inner;
    }
}

class FakeBufferHandler
{
    public function __construct(protected object $handler) {}
}

class FakeGroupHandler
{
    /** @param list<object> $handlers */
    public function __construct(protected array $handlers) {}
}

class FakeLogManager
{
    public function __construct(private array $handlers) {}

    public function channel(): object
    {
        $handlers = $this->handlers;

        return new class($handlers)
        {
            public function __construct(private array $handlers) {}

            public function getHandlers(): array
            {
                return $this->handlers;
            }
        };
    }
}
