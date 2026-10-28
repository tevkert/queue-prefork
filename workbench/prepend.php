<?php

// Fixture for the auto_prepend_file variant (docker/compose.prepend.yml). Records the
// pid and how often it ran in this process: a forked child must run it exactly once, like an exec'd
// process. App\Jobs\ReadsArgv records it.
$GLOBALS['__workbench_prepend'] = [
    'pid' => getmypid(),
    'runs' => ($GLOBALS['__workbench_prepend']['runs'] ?? 0) + 1,
];
