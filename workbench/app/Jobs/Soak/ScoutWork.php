<?php

namespace App\Jobs\Soak;

use App\Models\Source;
use App\Models\Post;
use Illuminate\Support\Facades\Cache;

/** Site search through Laravel Scout (database engine): max(1, $size / 10) post searches and one source search, cached briefly. */
class ScoutWork extends SoakJob
{
    private const TERMS = ['news', 'market', 'coffee', 'football', 'weather', 'review', 'travel', 'recipe',
        'music', 'science', 'history', 'phone', 'city', 'summer', 'section', 'idea', 'garden', 'river'];

    public function handle(): void
    {
        $random = $this->random();

        for ($i = 0; $i < max(1, intdiv($this->size, 10)); $i++) {
            $term = self::TERMS[$random->getInt(0, count(self::TERMS) - 1)];
            $page = $random->getInt(1, 3);

            Cache::remember("search:posts:{$term}:{$page}", 30, fn () => Post::search($term)
                ->query(fn ($query) => $query->with('source'))
                ->paginate(20, 'page', $page)
                ->map(fn (Post $post) => ['id' => $post->id, 'title' => $post->title, 'source' => $post->source?->name])
                ->all());
        }

        Source::search(self::TERMS[$random->getInt(0, count(self::TERMS) - 1)])->take(10)->get();
    }
}
