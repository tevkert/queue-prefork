<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Console\DoctorCommand;

class DoctorPidOneTest extends TestCase
{
    public static function pidOnes(): array
    {
        return [
            'Horizon as the container command' => ['php artisan horizon ', 'horizon'],
            'queue:work as the container command' => ['/usr/local/bin/php /var/www/html/artisan queue:work redis --tries=3 ', 'queue:work'],
            'a supervisor is not the master' => ['php artisan horizon:supervisor host:default redis ', null],
            "Docker's init" => ['/sbin/docker-init -- php artisan horizon ', null],
            'tini' => ['/usr/bin/tini -- php artisan horizon ', null],
            'supervisord' => ['/usr/bin/python3 /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf ', null],
            's6' => ['s6-svscan -t0 /var/run/s6/services ', null],
            'systemd' => ['/sbin/init splash ', null],
            'php-fpm' => ['php-fpm: master process (/usr/local/etc/php-fpm.conf) ', null],
        ];
    }

    #[DataProvider('pidOnes')]
    public function test_the_doctor_reports_a_pid_1_that_is_the_master_or_a_worker(string $cmdline, ?string $runsStock): void
    {
        foreach (['horizon', 'queue:work'] as $command) {
            $reasons = DoctorCommand::pidOne($cmdline, $command);

            $this->assertSame($command === $runsStock, $reasons !== [], "{$command}: ".implode('; ', $reasons));
        }
    }
}
