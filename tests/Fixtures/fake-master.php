<?php

// A stand-in for `php artisan horizon` (MasterHostTest), run as `php fake-master.php horizon`. Loading the
// package's autoloader runs the hook, which execs this process into a host template that forks the
// master; the master runs this script again from the top and plays a small master over the channel it
// inherited. Without the handoff (switched off, or the host fell back) it runs as a "stock" master.
// FIXTURE_* variables: STOCK (the master switches itself off; "stopped": and its program is stopped
// before it exits), TERM_EXIT, NO_TERM_HANDLER, UNIT (spawn one fake unit), OUT (the events file for the
// unit's fake loop).

require __DIR__.'/../../vendor/autoload.php';

use Tevkert\QueuePrefork\Runtime;

$mode = Runtime::$role === 'horizon-master' ? 'hosted' : 'stock';

if (! getenv('FIXTURE_NO_TERM_HANDLER')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function (): void {
        echo "TERM received\n";
        exit((int) (getenv('FIXTURE_TERM_EXIT') ?: 7));
    });
    pcntl_signal(SIGUSR2, fn () => print ("USR2 received\n"));
}

$status = (string) @file_get_contents('/proc/self/status');
$ignored = preg_match('/^SigIgn:\s+([0-9a-f]+)/m', $status, $m) ? $m[1] : '?';
echo "{$mode} master pid=".getmypid().' ppid='.posix_getppid().' host='.(Runtime::$hostPid ?? '-').' title='.cli_get_process_title()." sigign={$ignored}\n";

if ($mode === 'hosted') {
    $channel = Runtime::$channel;
    $ready = $channel->receiveOne(5);
    $opcache = $ready['opcache'] ?? [];
    echo 'ready from='.($ready['pid'] ?? '?').' host='.json_encode($ready['host'] ?? null).' opcache_mb='.round(($opcache['used_mb'] ?? 0) + ($opcache['free_mb'] ?? 0))."\n";

    if (getenv('FIXTURE_STOCK')) {
        if (getenv('FIXTURE_STOCK') === 'stopped') {
            pcntl_signal(SIGTERM, SIG_IGN); // the relayed TERM must not end this master: it exits 0 after asking
        }

        $channel->send(['t' => 'stock', 'reason' => 'fixture']);
        $channel->flushBlocking(1.0);

        if (getenv('FIXTURE_STOCK') === 'stopped') {
            posix_kill(Runtime::$hostPid, SIGTERM);
            usleep(300000);
        }

        exit(0);
    }

    $statusDir = sys_get_temp_dir().'/qp-fake-master-'.getmypid();
    @mkdir($statusDir, 0700);
    $channel->send(['t' => 'config', 'status_dir' => $statusDir, 'config' => ['exit_grace' => 5, 'hello_timeout' => 10]]);

    if (getenv('FIXTURE_UNIT')) {
        $loop = __DIR__.'/fake-loop.php';
        $channel->send([
            't' => 'spawn',
            'id' => 'u1',
            'cmd' => "exec php {$loop} horizon:supervisor test:fake redis --workers=1 --hold=30",
            'cwd' => dirname(__DIR__, 2),
            'env' => ['HP_TEST_OUT' => (string) getenv('FIXTURE_OUT'), 'HP_TEST_WORKER' => __DIR__.'/fake-worker.php', 'HP_TEST_MARKER' => 'hosted'] + getenv(),
        ]);

        while (($message = $channel->receiveOne(10)) !== null) {
            if (($message['t'] ?? null) === 'spawned') {
                echo 'unit spawned pid='.$message['pid']."\n";
                $channel->send(['t' => 'admit', 'id' => 'u1']);
                $channel->flushBlocking(1.0);

                break;
            }
        }
    }
}

while (true) {
    usleep(50000);
}
