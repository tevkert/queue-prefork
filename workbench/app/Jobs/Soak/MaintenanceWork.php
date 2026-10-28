<?php

namespace App\Jobs\Soak;

use App\Models\Post;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;
use Spatie\Valuestore\Valuestore;

/**
 * The maintenance pool's rare long job: a bulk upsert of $size soak_items (EloquentWork), then a
 * sitemap of the latest posts (spatie/laravel-sitemap) and a stats file (spatie/valuestore).
 */
class MaintenanceWork extends EloquentWork
{
    public function handle(): void
    {
        parent::handle();

        $sitemap = Sitemap::create();

        Post::query()->latest('updated_at')->limit(2000)->get(['slug', 'updated_at'])
            ->each(fn (Post $post) => $sitemap->add(Url::create('/p/'.$post->slug)->setLastModificationDate($post->updated_at)->setPriority(0.6)));

        $sitemap->writeToFile(storage_path('app/private/sitemap.xml'));

        $stats = Valuestore::make(storage_path('app/private/stats.json'));
        $stats->put('posts', Post::query()->count());
        $stats->put('sitemap_at', now()->toIso8601String());
        $stats->increment('maintenance_runs');
    }
}
