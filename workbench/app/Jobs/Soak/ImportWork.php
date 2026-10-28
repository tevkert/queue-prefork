<?php

namespace App\Jobs\Soak;

use App\Models\Source;
use App\Models\Post;
use DiDom\Document;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laminas\Feed\Reader\Reader;
use Mews\Purifier\Facades\Purifier;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * A feed import: fetches a source's feed from the stub (laminas-feed), then the full content of $size − 1
 * of its items from the stub's content API (50-400 ms each), reads it with DiDom, cleans the body with
 * mews/purifier and the excerpt with symfony/html-sanitizer, and upserts the posts: sluggable slugs,
 * activity log entries for new or changed posts, and one Scout index job (scout queue) for them.
 *
 * Posts are bounded: 200 sources × 60 posts.
 */
class ImportWork extends SoakJob
{
    public $tries = 3;

    public function handle(): void
    {
        $random = $this->random();
        $base = rtrim(config('workbench.stub_url'), '/');
        $number = $random->getInt(1, 200);

        $source = Source::query()->firstOrCreate(
            ['code' => 'source'.$number],
            ['name' => 'Source '.$number, 'url' => $base.'/s/'.$number, 'feed_url' => $base.'/feed?source='.$number],
        );

        $xml = Http::timeout(10)->connectTimeout(2)->get($base.'/feed', [
            'source' => $number, 'seed' => $random->getInt(1, 50), 'ms' => $random->getInt(50, 400),
        ])->throw()->body();

        $feed = Reader::importString($xml);
        $entries = [];

        foreach ($feed as $entry) {
            $entries[] = ['guid' => $entry->getId(), 'title' => $entry->getTitle(), 'link' => $entry->getLink(),
                'summary' => $entry->getDescription(), 'date' => $entry->getDateModified() ?? $entry->getDateCreated()];
        }

        $source->fill(['title' => $feed->getTitle(), 'description' => $feed->getDescription(), 'last_imported_at' => now()]);
        $source->save();

        $sanitizer = new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()->allowLinkSchemes(['https']));
        $changed = [];

        Post::withoutSyncingToSearch(function () use ($random, $entries, $source, $base, $sanitizer, &$changed) {
            for ($i = 0; $i < max(1, $this->size - 1) && $i < count($entries); $i++) {
                $entry = $entries[$random->getInt(0, count($entries) - 1)];
                $externalId = (int) Str::afterLast($entry['guid'], '-');

                $html = Http::timeout(10)->connectTimeout(2)->get($base.'/item', [
                    'source' => $source->id, 'item' => $externalId, 'ms' => $random->getInt(50, 400),
                ])->throw()->body();

                $document = new Document($html);
                $content = $document->first('article .content');
                $links = array_slice(array_map(fn ($a) => $a->getAttribute('href'), $document->find('article a')), 0, 20);
                $tags = array_map(fn ($li) => trim($li->text()), $document->find('ul.tags li'));
                $body = Purifier::clean($content?->html() ?? '');

                $post = Post::query()->firstOrNew(['source_id' => $source->id, 'external_id' => $externalId]);
                $post->fill([
                    'title' => Str::limit($document->first('h1')?->text() ?? $entry['title'], 200),
                    'excerpt' => Str::limit(strip_tags($sanitizer->sanitize($entry['summary'] ?? '')), 300),
                    'body' => $body,
                    'tags' => $tags,
                    'links' => $links,
                    'published_at' => $entry['date'],
                ]);
                $post->views = $externalId * 13 + $random->getInt(0, 5);

                if (! $post->exists || $post->isDirty(['title', 'excerpt', 'tags'])) {
                    $post->save();
                    $changed[] = $post;
                } else {
                    $post->saveQuietly();
                }
            }
        });

        if ($changed !== []) {
            collect($changed)->searchable();
        }
    }
}
