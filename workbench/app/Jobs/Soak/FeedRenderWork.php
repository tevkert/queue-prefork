<?php

namespace App\Jobs\Soak;

use App\Models\Post;
use Livewire\Livewire;
use Spatie\SchemaOrg\Schema;

/**
 * A page render off the request path (pre-rendered feed fragments): mounts the PostFeed Livewire
 * component (Flux components, Blaze-compiled post cards) for $size / 10 posts, plus the page's
 * schema.org JSON-LD (spatie/schema-org).
 */
class FeedRenderWork extends SoakJob
{
    public function handle(): void
    {
        $limit = max(10, intdiv($this->size, 10));
        $html = Livewire::mount('post-feed', ['limit' => $limit, 'offset' => $this->seed % 200]);

        $list = Schema::itemList()->itemListElement(
            Post::query()->latest('published_at')->limit($limit)->get(['id', 'title', 'slug'])
                ->map(fn (Post $post, $i) => Schema::listItem()->position($i + 1)->name($post->title)->url(url('/p/'.$post->slug)))
                ->all()
        );

        strlen($html.$list->toScript());
    }
}
