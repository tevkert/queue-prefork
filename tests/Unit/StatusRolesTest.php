<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Console\StatusCommand;

class StatusRolesTest extends TestCase
{
    public static function commandLines(): array
    {
        return [
            'host template' => ['php [hp-template:host]   ', 'template'],
            'Horizon template' => ['php [hp-template:0] ', 'template'],
            'queue:work template' => ['php [qp-template:169c9d9b1b13e05a] ', 'template'],
            'master' => ['php8.4 artisan horizon ', 'master'],
            'master with options' => ['/usr/bin/php -d memory_limit=-1 -dopcache.enable_cli=1 -n /srv/app/artisan horizon ', 'master'],
            'supervisor' => ['/usr/bin/php8.4 artisan horizon:supervisor host-a1b2:supervisor-1 redis --workers-name=default --balance=auto ', 'supervisor'],
            'fork server' => ['/usr/bin/php8.4 artisan horizon:supervisor host-a1b2:supervisor-1 redis --workers-name=default [fork-server]', 'fork-server'],
            'Horizon worker' => ['/usr/bin/php8.4 artisan horizon:work redis --name=default --supervisor=host-a1b2:supervisor-1 ', 'worker'],
            'queue:work worker' => ['php8.4 artisan queue:work redis --queue=default --tries=1 ', 'worker'],
            'stub' => ['/bin/sh /tmp/qp-1000/stub-3ad890d63ed1ed75.sh 4242 1793180248 7 3 /tmp/qp-1000 ', 'stub'],
            'horizon:status' => ['php artisan horizon:status ', null],
            'horizon:snapshot' => ['/usr/bin/php8.4 artisan horizon:snapshot ', null],
            'horizon:terminate' => ['php artisan horizon:terminate ', null],
            'queue:restart' => ['php artisan queue:restart ', null],
            'this command' => ['php artisan queue-prefork:status --json ', null],
            'tinker mentioning both' => ["php artisan tinker --execute dump(shell_exec('php artisan horizon')); // php [hp-template:host] ", null],
            'a shell wrapper' => ['sh -c php artisan horizon ', null],
        ];
    }

    #[DataProvider('commandLines')]
    public function test_a_process_role_comes_from_its_command_line(string $cmd, ?string $role): void
    {
        $this->assertSame($role, StatusCommand::roleOf($cmd));
    }
}
