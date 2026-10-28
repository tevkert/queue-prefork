<?php

namespace Tevkert\QueuePrefork\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tevkert\QueuePrefork\Support\Identity;

class IdentityTest extends TestCase
{
    public function test_parses_linux_stat_after_the_last_parenthesis(): void
    {
        // comm "php (x) y" contains spaces and parentheses; state Z, ppid 77, starttime 123456 (field 22).
        $raw = '4242 (php (x) y) Z 77 4242 4242 0 -1 4194560 100 0 0 0 1 2 0 0 20 0 1 0 123456 1000 10 18446744073709551615';
        $stat = Identity::parseLinuxStat($raw);

        $this->assertSame(['state' => 'Z', 'ppid' => 77, 'start' => '123456'], $stat);
    }

    public function test_this_process_is_alive_and_a_wrong_start_time_is_not(): void
    {
        $pid = getmypid();
        $start = Identity::startTime($pid);

        $this->assertNotNull($start);
        $this->assertTrue(Identity::alive($pid, $start));
        $this->assertFalse(Identity::alive($pid, 'not-the-start-time'));
        $this->assertFalse(Identity::alive(999999, null));
    }

    public function test_a_reaped_child_is_dead_and_an_unreaped_one_is_a_zombie(): void
    {
        $pid = pcntl_fork();

        if ($pid === 0) {
            exit(0);
        }

        usleep(300000);
        Identity::forgetCache();
        $this->assertFalse(Identity::alive($pid, null), 'an exited, unreaped child (zombie) counts as dead');
        pcntl_waitpid($pid, $status);
        Identity::forgetCache();
        $this->assertNull(Identity::stat($pid));
    }
}
