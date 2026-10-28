<?php

namespace App\Jobs\Soak;

use App\Ai\Agents\PostEnricher;
use App\Models\Post;

/**
 * Post enrichment: one structured completion through laravel/ai (OpenRouter driver, pointed
 * at the stub, which answers in 1.5-4 s), then the post is updated: activity log, Scout re-index.
 */
class LlmWork extends SoakJob
{
    public $tries = 2;

    public $timeout = 60;

    public function handle(): void
    {
        $random = $this->random();
        $post = Post::query()->orderBy('id')->skip($random->getInt(0, 500))->first() ?? Post::query()->first();

        if ($post === null) {
            return;
        }

        $response = (new PostEnricher)->prompt(strip_tags((string) $post->body));

        $post->update([
            'excerpt' => $response['description'] ?? $post->excerpt,
            'tags' => $response['tags'] ?? $post->tags,
            'is_sensitive' => (bool) ($response['is_sensitive'] ?? false),
            'enriched_at' => now(),
        ]);
    }
}
