<?php

namespace App\Jobs\Soak;

/** map, filter, sort and group over $size items (50k in the mix). */
class CollectionWork extends SoakJob
{
    public function handle(): void
    {
        $seed = $this->seed;

        collect(range(1, $this->size))
            ->map(fn (int $i) => ['id' => $i, 'score' => ($i * 7919 + $seed) % 100_003, 'group' => $i % 37])
            ->filter(fn (array $row) => $row['score'] % 3 !== 0)
            ->sortByDesc('score')
            ->groupBy('group')
            ->map(fn ($rows) => $rows->sum('score'))
            ->sortKeys()
            ->all();
    }
}
