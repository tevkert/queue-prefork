<?php

// Inspection helper for the integration tests. Runs inside an app container (docker exec) and prints
// one JSON object: the Horizon process tree from /proc, Horizon's own master and supervisor records,
// and the prefork snapshot.

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\MasterSupervisor;
use Tevkert\QueuePrefork\Support\Identity;

require '/repo/workbench/vendor/autoload.php';
$app = require '/repo/workbench/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$processes = [];

foreach (glob('/proc/[0-9]*') ?: [] as $dir) {
    $pid = (int) basename($dir);
    $cmd = trim(str_replace("\0", ' ', (string) @file_get_contents($dir.'/cmdline')));

    $role = match (true) {
        str_contains($cmd, 'hp-template') => 'template',
        str_contains($cmd, '[fork-server]') => 'fork-server',
        str_contains($cmd, 'horizon:supervisor') => 'supervisor',
        str_contains($cmd, 'horizon:work') => 'worker',
        (bool) preg_match('#artisan(-horizon)? horizon$#', $cmd) => 'master',
        default => null,
    };

    if ($role === null || $pid === getmypid()) {
        continue;
    }

    $stat = Identity::stat($pid);

    if ($stat === null) {
        continue; // exited while listing
    }

    $processes[] = ['pid' => $pid, 'ppid' => $stat['ppid'], 'role' => $role, 'state' => $stat['state'], 'start' => $stat['start'], 'cmd' => $cmd];
}

$snapshot = Redis::connection('horizon')->get('prefork:snapshot:'.MasterSupervisor::basename());

echo json_encode([
    'processes' => $processes,
    'masters' => array_map(fn ($m) => ['name' => $m->name, 'pid' => (int) $m->pid, 'status' => $m->status], app(MasterSupervisorRepository::class)->all()),
    'supervisors' => array_map(fn ($s) => ['name' => $s->name, 'pid' => (int) $s->pid, 'status' => $s->status, 'processes' => (array) $s->processes], app(SupervisorRepository::class)->all()),
    'snapshot' => $snapshot ? json_decode($snapshot, true) : null,
], JSON_UNESCAPED_SLASHES), "\n";
