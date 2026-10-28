<?php

return [

    // On by default. QUEUE_PREFORK=false turns everything off; QUEUE_PREFORK_QUEUE_WORK=false or
    // QUEUE_PREFORK_HORIZON=false one mode. As with any setting, run `php artisan config:cache` after a
    // change when the config is cached. The runtime switch (`php artisan queue-prefork:switch disable
    // [--mode=…]`) and the files storage/framework/queue-prefork.off[-queue-work|-horizon] need no re-cache.
    'enabled' => env('QUEUE_PREFORK', true),
    'queue_work' => env('QUEUE_PREFORK_QUEUE_WORK', true),
    'horizon' => env('QUEUE_PREFORK_HORIZON', true),

    // The templates' opcache. Its shared memory holds the compiled code of the whole app.
    'opcache' => [
        'memory_consumption' => env('QUEUE_PREFORK_OPCACHE_MB', 192),
        'interned_strings_buffer' => env('QUEUE_PREFORK_INTERNED_MB', 32),
        'max_accelerated_files' => env('QUEUE_PREFORK_MAX_FILES', 20000),
    ],

    // Fork safety: descriptors the templates may hold, as comma-separated patterns matched against what
    // the audit reports (`socket socket:[12345]`, `anon_inode:[eventpoll]`; e.g. 'socket*'), and the
    // check for threads.
    'allow_descriptors' => env('QUEUE_PREFORK_ALLOW_DESCRIPTORS', ''),
    'thread_audit' => env('QUEUE_PREFORK_THREAD_AUDIT', true),

    // queue:work: boot the app once in a fresh template before its first worker, and kill a worker's
    // process group when its process manager's stub is killed.
    'warmup' => env('QUEUE_PREFORK_WARMUP', true),
    'kill_group' => env('QUEUE_PREFORK_KILL_GROUP', true),

    // queue:work on Linux without FFI: worker output goes to storage/logs/queue-prefork-*.log, rotated at
    // this size and kept this many times; 'stock' runs those workers stock instead.
    'shared_log' => [
        'max_mb' => env('QUEUE_PREFORK_LOG_MAX_MB', 10),
        'files' => env('QUEUE_PREFORK_LOG_FILES', 3),
    ],
    'output' => env('QUEUE_PREFORK_OUTPUT'),

    // Seconds.
    'template_timeout' => 5,
    'hello_timeout' => 15,
    'spawn_timeout' => 10,
    'exit_grace' => 15,

    'allow_unprofiled_workers' => false,
    'allow_untested_horizon' => false,
];
