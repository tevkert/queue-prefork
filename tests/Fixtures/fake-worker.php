<?php

// A stand-in worker (W) for the mechanics tests: reports readiness, records its identity, exits.

use Tevkert\QueuePrefork\Runtime;

$exit = 0;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--exit=')) {
        $exit = (int) substr($arg, 7);
    }
}

Runtime::$channel->send(['t' => 'ready', 'id' => Runtime::$requestId]);
Runtime::$channel->flushBlocking(1.0);

file_put_contents(getenv('HP_TEST_OUT'), json_encode([
    'e' => 'worker',
    'pid' => getmypid(),
    'ppid' => posix_getppid(),
    'argv' => $argv,
    'server_argv' => $_SERVER['argv'],
    'script_name' => $_SERVER['SCRIPT_NAME'],
    'title' => cli_get_process_title(),
    'mt_rand' => mt_rand(),
    'env' => getenv('HP_TEST_WORKER_ENV'),
    'opcache' => function_exists('opcache_get_status') ? (bool) (opcache_get_status(false)['opcache_enabled'] ?? false) : false,
    'stdin_ok' => is_resource(STDIN) && @stream_isatty(STDIN) === false,
])."\n", FILE_APPEND | LOCK_EX);

exit($exit);
