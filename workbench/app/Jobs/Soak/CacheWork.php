<?php

namespace App\Jobs\Soak;

use Illuminate\Support\Facades\Cache;

/** $size Redis cache reads, with a put on every miss (keys 1..500, 1-4 KB values, 5 min TTL). */
class CacheWork extends SoakJob
{
    public function handle(): void
    {
        $random = $this->random();

        for ($i = 0; $i < $this->size; $i++) {
            $key = 'soak:item:'.$random->getInt(1, 500);
            $value = Cache::get($key);

            if ($value === null) {
                Cache::put($key, [
                    'html' => str_repeat(bin2hex($random->getBytes(16)), $random->getInt(32, 128)),
                    'at' => time(),
                ], 300);
            }
        }

        Cache::increment('soak:cache-work-runs');
    }
}
