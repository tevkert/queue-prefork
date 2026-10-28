<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\CommandLine;

class CommandLineTest extends TestCase
{
    public function test_parses_horizons_worker_command_line(): void
    {
        $line = "exec '/usr/bin/php8.4' artisan horizon:work redis --name=default --supervisor=web-1-AbCd:supervisor-imports --backoff=0 --max-time=3600 --max-jobs=0 --memory=128 --queue=\"import,refresh\" --sleep=3 --timeout=86400 --tries=1 --rest=0 --force";
        $command = CommandLine::parse($line);

        $this->assertSame('/usr/bin/php8.4', $command->php);
        $this->assertSame('artisan', $command->script);
        $this->assertSame('horizon:work', $command->command);
        $this->assertSame('artisan', $command->argv[0], 'the script stays argv[0], as ArgvInput expects');
        $this->assertContains('--queue=import,refresh', $command->argv);
        $this->assertSame('/srv/app/artisan', $command->entry('/srv/app'));
        $this->assertSame('/usr/bin/php8.4 artisan horizon:work redis --name=default --supervisor=web-1-AbCd:supervisor-imports --backoff=0 --max-time=3600 --max-jobs=0 --memory=128 --queue=import,refresh --sleep=3 --timeout=86400 --tries=1 --rest=0 --force', $command->title());
        $this->assertSame('86400', $command->option('timeout'));
    }

    public function test_parses_a_supervisor_command_line_with_nice(): void
    {
        $command = CommandLine::parse("exec '/usr/local/bin/php' artisan horizon:supervisor h-AbCd:supervisor-default redis --workers-name=default --balance=auto --nice=5 --queue=\"default\"");

        $this->assertSame('horizon:supervisor', $command->command);
        $this->assertSame('5', $command->option('nice'));
        $this->assertSame('/abs/artisan', CommandLine::parse('exec php /abs/artisan horizon:work')->entry('/elsewhere'));
    }

    #[DataProvider('rejected')]
    public function test_rejects_anything_but_plain_words(string $line): void
    {
        $this->expectException(InvalidArgumentException::class);
        CommandLine::parse($line);
    }

    public static function rejected(): array
    {
        return [
            'pipe' => ['exec php artisan horizon:work | tee x'],
            'and' => ['exec php artisan horizon:work && true'],
            'substitution' => ['exec php artisan horizon:work --queue="$(id)"'],
            'env assignment' => ['FOO=1 php artisan horizon:work'],
            'wrapper' => ['exec nice -n 5 php artisan horizon:work'],
            'other command' => ['exec php artisan queue:work'],
            'unterminated quote' => ["exec 'php artisan horizon:work"],
        ];
    }
}
