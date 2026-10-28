<?php

use Illuminate\Support\Str;

/*
 * Horizon for the queue-prefork workbench: six supervisors, laid out like a busy production app.
 *
 *   import       feed imports: the busiest pool, I/O-bound, auto 2-7
 *   media        a transcoding stand-in: CPU-bound, niced, recycled every 500 jobs, auto 1-3
 *   background   a merged low-volume pool (default, ai, scout; queue order is priority):
 *                slow LLM calls and fast index writes, auto 1-5 with a floor of one per queue
 *   notify       notifications: a fixed pool of 2
 *   maintenance  backfills and reindexing: one worker, long bulk jobs, no balancing
 *   digest       fan-out bursts every few minutes, scaled by queue size, auto 1-4
 *
 * Workers are recycled differently per pool (maxTime 1 h, 30 min or never; maxJobs 500), so the
 * soak keeps spawning and retiring workers all day, as a real deployment does.
 *
 * APP_ENV is `prefork-test` in the containers (never `testing`, under which Laravel skips console
 * events), so the production block is also registered under that name.
 *
 * Worker timeouts stay below the Redis queue's retry_after (REDIS_QUEUE_RETRY_AFTER, 180 in
 * docker/compose.yml), so a job is never handed to a second worker while the first still runs it.
 */

$production = [
    'import' => [
        'minProcesses' => 2,
        'maxProcesses' => 7,
        'balanceMaxShift' => 3,
        'balanceCooldown' => 3,
        'force' => true,
    ],
    'media' => [
        'minProcesses' => 1,
        'maxProcesses' => 3,
        'balanceMaxShift' => 1,
        'balanceCooldown' => 5,
        'force' => true,
    ],
    'background' => [
        'minProcesses' => 1,
        'maxProcesses' => 5,
        'balanceMaxShift' => 1,
        'balanceCooldown' => 5,
        'force' => true,
    ],
    'notify' => [
        'minProcesses' => 2,
        'maxProcesses' => 2,
        'force' => true,
    ],
    'maintenance' => [
        'minProcesses' => 1,
        'maxProcesses' => 1,
        'force' => true,
    ],
    'digest' => [
        'minProcesses' => 1,
        'maxProcesses' => 4,
        'balanceMaxShift' => 2,
        'balanceCooldown' => 2,
        'force' => true,
    ],
];

return [

    'name' => env('HORIZON_NAME'),

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    // Each app container sets its own prefix (docker/compose.yml), so stock and prefork never share
    // Horizon state even if they were pointed at the same Redis database.
    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'
    ),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [
        // App\Jobs\ExampleJob::class,
    ],

    'silenced_tags' => [
        // 'notifications',
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    // On by default, as in production. HORIZON_FAST_TERMINATION=false switches it off.
    'fast_termination' => (bool) env('HORIZON_FAST_TERMINATION', true),

    'memory_limit' => 512,

    'defaults' => [
        'import' => [
            'connection' => 'redis',
            'queue' => ['import'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxTime' => 60 * 60,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => (int) env('HORIZON_TIMEOUT', 150),
            'nice' => 0,
            'force' => true,
        ],
        'media' => [
            'connection' => 'redis',
            'queue' => ['media'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxTime' => 0,
            'maxJobs' => 500,
            'memory' => 256,
            'tries' => 2,
            'timeout' => (int) env('HORIZON_TIMEOUT', 150),
            'nice' => 5,
            'force' => true,
        ],
        'background' => [
            'connection' => 'redis',
            'queue' => ['default', 'ai', 'scout'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'time',
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 2,
            'timeout' => (int) env('HORIZON_TIMEOUT', 150),
            'nice' => 5,
            'force' => true,
        ],
        'notify' => [
            'connection' => 'redis',
            'queue' => ['notify'],
            'balance' => 'simple',
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
            'force' => true,
        ],
        'maintenance' => [
            'connection' => 'redis',
            'queue' => ['maintenance'],
            'balance' => false,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => (int) env('HORIZON_TIMEOUT', 150),
            'nice' => 10,
            'force' => true,
        ],
        'digest' => [
            'connection' => 'redis',
            'queue' => ['digest'],
            'balance' => 'auto',
            'autoScalingStrategy' => 'size',
            'maxTime' => 30 * 60,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => (int) env('HORIZON_TIMEOUT', 150),
            'nice' => 0,
            'force' => true,
        ],
    ],

    'environments' => [
        'production' => $production,
        'prefork-test' => $production,
    ],

    'watch' => [
        'app',
        'bootstrap',
        'config/**/*.php',
        'database/**/*.php',
        'resources/**/*.php',
        'routes',
        'composer.lock',
        'composer.json',
        '.env',
    ],
];
