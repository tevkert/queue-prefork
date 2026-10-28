<?php

// queue-prefork template launcher. The Horizon master copies this file into its private runtime
// directory and starts it; see Tevkert\QueuePrefork\Server\Template. Only forked supervisor loops and
// workers get past main(): they run their normal entry script here, in global scope, as an exec would.

require getenv('HP_PACKAGE_DIR').'/src/Server/loader.php';

$__hpJob = getenv('QP_SERVER') === 'queue'
    ? \Tevkert\QueuePrefork\Server\WorkServer::main()
    : \Tevkert\QueuePrefork\Server\Template::main();

$argv = $__hpJob->argv;
$argc = count($argv);
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = $argc;
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_FILENAME'] = $_SERVER['PHP_SELF'] = $__hpJob->script;
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
$_SERVER['REQUEST_TIME'] = (int) $_SERVER['REQUEST_TIME_FLOAT'];

if ($__hpJob->prependFile !== '') {
    require $__hpJob->prependFile;
}

$__hpEntry = $__hpJob->entry;
unset($__hpJob);

require $__hpEntry;
