<?php

// A spawn client with a forged context (QueueGatesTest), run as the auto_prepend_file of a real
// `php artisan queue:work` of the fixture release, so the template's /proc checks of the peer pass.
// Every context field in its message is a lie: the worker it gets must have the kernel's values.

if (class_exists(\Tevkert\QueuePrefork\Runtime::class, false)) {
    return; // the forked worker runs this prepend too: there it is just a prepend
}

require __DIR__.'/../../../vendor/autoload.php';

use Tevkert\QueuePrefork\Runtime;
use Tevkert\QueuePrefork\Support\Native;
use Tevkert\QueuePrefork\Support\RuntimeDir;
use Tevkert\QueuePrefork\Support\TemplateKey;

$native = Native::get();
$key = TemplateKey::compute(dirname(realpath($_SERVER['SCRIPT_FILENAME'])), 'normal');
$fd = $native->connect(RuntimeDir::shared()."/{$key['key16']}.sock", 3.0);

$native->sendWithFds($fd, json_encode([
    't' => 'spawn',
    'id' => bin2hex(random_bytes(8)),
    'pid' => getmypid(),
    'protocol' => Runtime::PROTOCOL,
    'prepend' => __FILE__,
    'stdio' => [],
    'argv' => [PHP_BINARY, '/forged/artisan', 'queue:work', '--forged'],
    'entry' => '/forged/artisan',
    'cwd' => '/',
    'env' => ['APP_CONFIG_CACHE' => '/forged/config.php', 'FIXTURE_FORGE_ENV' => '1'],
    'umask' => 0,
    'limits' => ['soft openfiles' => 17, 'hard openfiles' => 17],
    'nice' => 10,
])."\n", [0, 1, 2]);

$inbox = '';

while (true) {
    while (! str_contains($inbox, "\n")) {
        $got = $native->receive($fd, 4096, 0);

        if ($got === null) {
            continue;
        }

        if ($got['eof']) {
            fwrite(STDERR, "forge: the template closed the connection\n");
            exit(1);
        }

        $inbox .= $got['data'];
    }

    [$line, $inbox] = explode("\n", $inbox, 2);
    $reply = json_decode($line, true);

    if (($reply['t'] ?? null) === 'spawned') {
        $native->send($fd, "{\"t\":\"go\"}\n");
    } elseif (($reply['t'] ?? null) !== 'waiting') {
        exit(is_numeric($line) ? (int) $line : 1); // the status line, or a refusal
    }
}
