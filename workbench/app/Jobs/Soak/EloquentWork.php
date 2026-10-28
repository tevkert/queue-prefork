<?php

namespace App\Jobs\Soak;

use App\Models\SoakItem;
use Illuminate\Support\Facades\DB;

/** Upserts $size rows of soak_items (keys 1..5000, so the table stays at a few thousand rows), reads a range, updates some. */
class EloquentWork extends SoakJob
{
    public function handle(): void
    {
        $random = $this->random();
        $now = now();
        $rows = [];

        for ($i = 0; $i < $this->size; $i++) {
            $key = $random->getInt(1, 5000);
            $rows[$key] = [
                'key' => $key,
                'name' => 'item-'.$key.'-'.bin2hex($random->getBytes(6)),
                'score' => $random->getInt(0, 100_000),
                'payload' => json_encode(['words' => $random->getInt(500, 9000), 'tags' => ['a', 'b', bin2hex($random->getBytes(3))]]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Concurrent upserts from several workers deadlock in InnoDB when they lock overlapping keys in
        // different orders (SQLSTATE 40001 / 1213). Lock in key order, and
        // let a transaction retry the rare deadlock that remains, as a production job would.
        ksort($rows);
        DB::transaction(fn () => SoakItem::upsert(array_values($rows), ['key'], ['name', 'score', 'payload', 'updated_at']), 3);

        $low = $random->getInt(0, 90_000);
        $items = SoakItem::query()->whereBetween('score', [$low, $low + 10_000])->orderBy('score')->limit(200)->get();

        $items->take(10)->each(fn (SoakItem $item) => $item->increment('hits'));

        DB::transaction(fn () => SoakItem::query()
            ->whereIn('key', array_slice(array_keys($rows), 0, 50))
            ->update(['hits' => DB::raw('hits + 1')]), 3);

        SoakItem::query()->latest('updated_at')->limit(50)->get()->sum('score');
    }
}
