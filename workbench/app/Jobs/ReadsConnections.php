<?php

namespace App\Jobs;

use App\Support\JobResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/** Fixture: records this worker's MySQL and Redis connection ids; no two workers share one. */
class ReadsConnections implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function handle(): void
    {
        JobResult::record('ReadsConnections', [
            'mysql' => DB::selectOne('select connection_id() as id')->id,
            'redis' => Redis::connection('default')->client()->client('id'),
            'redis_cache' => Redis::connection('cache')->client()->client('id'),
        ]);
    }
}
