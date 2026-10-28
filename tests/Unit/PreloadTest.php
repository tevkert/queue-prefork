<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tevkert\QueuePrefork\Runtime;

class PreloadTest extends TestCase
{
    public function test_preload_lists_every_class_without_a_framework_parent(): void
    {
        $expected = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src'));

        foreach ($files as $file) {
            $source = $file->getExtension() === 'php' ? (string) file_get_contents($file->getPathname()) : '';

            if (preg_match('/^namespace (\S+);/m', $source, $ns) && preg_match('/^(?:final |abstract )?class (\w+)(?! extends)\b/m', $source, $class)) {
                $expected[] = $ns[1].'\\'.$class[1];
            }
        }

        sort($expected);
        $listed = Runtime::CLASSES;
        sort($listed);

        $this->assertSame($expected, $listed);
    }

    public function test_preload_loads_them(): void
    {
        Runtime::preload();

        foreach (Runtime::CLASSES as $class) {
            $this->assertTrue(class_exists($class, false), $class);
        }
    }
}
