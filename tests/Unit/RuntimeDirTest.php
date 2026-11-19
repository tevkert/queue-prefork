<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\StatusFiles;

class RuntimeDirTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir().'/hp-test-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0700);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->base));
    }

    public function test_creates_a_private_directory_and_writes_atomically(): void
    {
        $dir = $this->base.'/rt';
        RuntimeDir::ensure($dir);

        $this->assertSame(0700, fileperms($dir) & 0777);
        RuntimeDir::writeAtomic($dir, 'a.final', '13');
        $this->assertSame('13', RuntimeDir::read($dir, 'a.final'));
    }

    public function test_xdg_runtime_dir_counts_only_resolved_and_out_of_other_users_reach(): void
    {
        $fallback = rtrim(sys_get_temp_dir(), '/').'/qp-'.posix_geteuid();
        $previous = getenv('XDG_RUNTIME_DIR');
        mkdir($this->base.'/private', 0700);
        symlink($this->base.'/private', $this->base.'/link');
        mkdir($this->base.'/open', 0777);
        chmod($this->base.'/open', 0777); // writable by others, not sticky: they could rename what's inside
        mkdir($this->base.'/open/private', 0700);
        mkdir($this->base.'/loose', 0755);
        chmod($this->base.'/loose', 0755);

        try {
            putenv('XDG_RUNTIME_DIR='.$this->base.'/link');
            $this->assertSame(realpath($this->base.'/private').'/qp-'.posix_geteuid(), RuntimeDir::shared(), 'a symlink resolves');

            putenv('XDG_RUNTIME_DIR='.$this->base.'/open/private');
            $this->assertSame($fallback, RuntimeDir::shared(), 'a replaceable parent');

            putenv('XDG_RUNTIME_DIR='.$this->base.'/loose');
            $this->assertSame($fallback, RuntimeDir::shared(), 'not private');
        } finally {
            putenv($previous === false ? 'XDG_RUNTIME_DIR' : "XDG_RUNTIME_DIR={$previous}");
        }
    }

    public function test_refuses_a_wrong_mode(): void
    {
        $dir = $this->base.'/open';
        mkdir($dir, 0755);
        chmod($dir, 0755);

        $this->expectException(RuntimeException::class);
        RuntimeDir::check($dir);
    }

    public function test_refuses_a_symlink(): void
    {
        $real = $this->base.'/real';
        mkdir($real, 0700);
        symlink($real, $this->base.'/link');

        $this->expectException(RuntimeException::class);
        RuntimeDir::check($this->base.'/link');
    }

    public function test_the_launcher_is_a_link_to_the_package_file(): void
    {
        $dir = $this->base.'/rt';
        RuntimeDir::ensure($dir);
        $package = dirname(__DIR__, 2);

        $launcher = RuntimeDir::launcher($dir, $package);

        $this->assertStringStartsWith($dir.'/template-', $launcher, 'the command line keeps the runtime path');
        $this->assertTrue(is_link($launcher));
        $this->assertSame(realpath($package.'/bin/template.php'), realpath($launcher), 'PHP names the package file in stack traces');
        $this->assertSame($launcher, RuntimeDir::launcher($dir, $package), 'reused');
    }

    public function test_launchers_of_a_removed_release_are_removed(): void
    {
        $dir = $this->base.'/rt';
        RuntimeDir::ensure($dir);
        mkdir($this->base.'/old/bin', 0700, true);
        file_put_contents($this->base.'/old/bin/template.php', '<?php');
        $old = RuntimeDir::launcher($dir, $this->base.'/old');
        $copy = RuntimeDir::copy($dir, 'template', 'php', '<?php // 1.1.1');
        exec('rm -rf '.escapeshellarg($this->base.'/old'));

        $current = RuntimeDir::launcher($dir, dirname(__DIR__, 2));

        $this->assertFalse(is_link($old), 'the dangling link is gone');
        $this->assertFileExists($copy, 'a copy from an earlier version stays: a template may be starting from it');
        $this->assertTrue(is_link($current));
    }

    public function test_never_reads_a_symlinked_status_file(): void
    {
        $dir = $this->base.'/rt';
        RuntimeDir::ensure($dir);
        file_put_contents($this->base.'/forged', '0');
        symlink($this->base.'/forged', $dir.'/u.final');

        $this->assertNull(RuntimeDir::read($dir, 'u.final'));
    }

    public function test_status_resolution_order(): void
    {
        $dir = $this->base.'/rt';
        RuntimeDir::ensure($dir);

        $this->assertSame(['status' => 137, 'source' => 'unknown'], StatusFiles::resolve($dir, 'u1', null));

        StatusFiles::writeIntent($dir, 'u1', 0);
        $this->assertSame(['status' => 137, 'source' => 'intent-only'], StatusFiles::resolve($dir, 'u1', null), 'an intent of 0 is never trusted alone');
        $this->assertSame(['status' => 13, 'source' => 'template'], StatusFiles::resolve($dir, 'u1', 13));

        StatusFiles::writeFinal($dir, 'u1', 2);
        $this->assertSame(['status' => 2, 'source' => 'final'], StatusFiles::resolve($dir, 'u1', 13));
    }
}
