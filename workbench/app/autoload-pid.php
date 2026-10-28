<?php

// Fixture: an `autoload.files` entry with a side effect. Composer runs it once in
// every process that loads vendor/autoload.php, so it must see that process's own pid, also in a
// forked child. App\Jobs\Fixtures\ReadsArgv records it next to getmypid().
$GLOBALS['__workbench_autoload_pid'] = getmypid();
