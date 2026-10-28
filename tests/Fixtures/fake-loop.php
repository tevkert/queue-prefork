<?php

// A stand-in supervisor loop (L) for the mechanics tests: it speaks the F↔L protocol directly.
// Options: --workers=N --worker-exit=C --exit=C --no-hello --armed-after=S --hold=S

use Tevkert\QueuePrefork\Runtime;

$opts = [];

foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    }
}

$out = getenv('HP_TEST_OUT');
$log = function (array $event) use ($out): void {
    file_put_contents($out, json_encode($event + ['pid' => getmypid()])."\n", FILE_APPEND | LOCK_EX);
};

$channel = Runtime::$channel;
$log([
    'e' => 'loop-started',
    'ppid' => posix_getppid(),
    'argv' => $argv,
    'title' => cli_get_process_title(),
    'server' => Runtime::$serverPid,
    'unit' => Runtime::$unitId,
    'env_marker' => getenv('HP_TEST_MARKER'),
    'role_env' => getenv('HP_ROLE'),
    'config' => Runtime::$config,
    'status_dir' => Runtime::$statusDir,
]);

$signals = [];
pcntl_async_signals(true);

if (! isset($opts['no-hello'])) {
    $channel->send(['t' => 'hello']);
}

$armedAfter = (float) ($opts['armed-after'] ?? 0);

if ($armedAfter > 0) {
    usleep((int) ($armedAfter * 1e6)); // handlers not installed yet: USR2 would kill us if relayed now
}

foreach ([SIGUSR2, SIGCONT, SIGTERM] as $signal) {
    pcntl_signal($signal, function (int $signo) use (&$signals, $log) {
        $signals[] = $signo;
        $log(['e' => 'signal', 'sig' => $signo]);
    });
}

$channel->send(['t' => 'armed']);
$channel->flushBlocking(1.0);

$worker = getenv('HP_TEST_WORKER');
$workers = (int) ($opts['workers'] ?? 0);
$pending = [];

for ($i = 1; $i <= $workers; $i++) {
    $id = "w{$i}";
    $pending[$id] = true;
    $channel->send([
        't' => 'spawn',
        'id' => $id,
        'cmd' => "exec php {$worker} horizon:work redis --supervisor=test:fake --n={$i} --exit=".($opts['worker-exit'] ?? 0),
        'cwd' => getcwd(),
        'env' => getenv() + ['HP_TEST_WORKER_ENV' => "from-loop-{$i}"],
    ]);
}

$deadline = microtime(true) + 20;
$ready = [];

while ($pending !== [] && microtime(true) < $deadline) {
    $message = $channel->receiveOne(0.5);

    if ($message === null) {
        if ($channel->eof) {
            $log(['e' => 'server-lost']);
            exit(1);
        }

        continue;
    }

    $log(['e' => 'from-server', 'm' => $message]);

    if ($message['t'] === 'spawned') {
        $channel->send(['t' => 'admit', 'id' => $message['id'], 'paused' => false]);
    } elseif ($message['t'] === 'ready') {
        $ready[] = $message['id'];
    } elseif ($message['t'] === 'exited') {
        unset($pending[$message['id']]);
    }
}

$hold = (float) ($opts['hold'] ?? 0);
$until = microtime(true) + $hold;

while (microtime(true) < $until) {
    $message = $channel->receiveOne(0.2);

    if ($channel->eof) {
        $log(['e' => 'server-lost']);
        exit(1);
    }

    if (in_array(SIGTERM, $signals, true)) {
        $log(['e' => 'terminating']);
        exit(0);
    }
}

$log(['e' => 'loop-exit', 'ready' => $ready, 'signals' => $signals]);
exit((int) ($opts['exit'] ?? 0));
