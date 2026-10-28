<?php

// The HTTP stub every outbound call of the workbench goes to (php -S, PHP_CLI_SERVER_WORKERS workers).
// Responses are deterministic in their query parameters (or request body), so the stock and prefork
// stacks get identical answers.
//
//   GET  /?ms=&kb=&seed=          JSON API call: sleeps ms (default 20-80), returns kb KB of JSON
//   GET  /feed?source=&seed=&ms=  RSS 2.0 feed of a source: 10 items with ids from 1..60
//   GET  /item?source=&item=&ms=  an item's full content as HTML (paragraphs, images, links, tags: input
//                                 for DiDom and the sanitisers), as a content API would return it
//   POST /v1/chat/completions     OpenAI-style chat completion (laravel/ai); sleeps 1.5-4 s, chosen
//                                 by a hash of the request body; content is the JSON the agent asks for
//   POST /push/<id>               web push endpoint: 201

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$ms = isset($_GET['ms']) ? max(0, min(10000, (int) $_GET['ms'])) : null;

if ($path === '/feed') {
    usleep(($ms ?? 50) * 1000);
    $source = (int) ($_GET['source'] ?? 1);
    $seed = (int) ($_GET['seed'] ?? 1);
    mt_srand($source * 7919 + $seed);
    $items = '';

    for ($i = 0; $i < 10; $i++) {
        $id = mt_rand(1, 60);
        $date = gmdate(DATE_RSS, 1790000000 + $source * 3600 + $id * 600);
        $items .= "<item><title>Source {$source} item {$id}: ".words($source * 1000 + $id, 6)."</title>"
            ."<link>http://stub:8080/item?source={$source}&amp;item={$id}</link>"
            ."<guid isPermaLink=\"false\">{$source}-{$id}</guid><pubDate>{$date}</pubDate>"
            ."<description><![CDATA[<p>".words($source * 1000 + $id + 1, 30)."</p>]]></description>"
            ."<category>".words($id, 1)."</category></item>";
    }

    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel>'
        ."<title>Source {$source}</title><link>http://stub:8080/s/{$source}</link>"
        ."<description>".words($source, 12)."</description>{$items}</channel></rss>";

    return;
}

if ($path === '/item') {
    usleep(($ms ?? 80) * 1000);
    $source = (int) ($_GET['source'] ?? 1);
    $item = (int) ($_GET['item'] ?? 1);
    $key = $source * 1000 + $item;
    $paragraphs = '';

    for ($i = 0; $i < 8 + $key % 9; $i++) {
        $paragraphs .= '<p style="color:#333">'.words($key + $i * 31, 40 + ($key + $i) % 40)
            .' <a href="https://example.com/'.$key.'/'.$i.'" target="_blank">link '.$i.'</a>'
            .' <b>'.words($key + $i, 3).'</b></p>';

        if ($i % 3 === 1) {
            $paragraphs .= '<figure><img src="https://cdn.example.com/'.$key.'/'.$i.'.jpg" width="640" height="360" alt="'.words($i, 2).'"><figcaption>'.words($key - $i, 6).'</figcaption></figure>';
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<article class="item" data-id="'.$key.'"><h1>'.words($key, 8).'</h1><time datetime="2026-10-26">26 Oct</time>'
        .'<div class="content">'.$paragraphs.'</div><ul class="tags"><li>'.words($key, 1).'</li><li>'.words($key + 1, 1).'</li></ul>'
        .'</article>';

    return;
}

if ($path === '/v1/chat/completions') {
    $body = file_get_contents('php://input') ?: '';
    $hash = crc32($body);
    usleep((1500 + $hash % 2500) * 1000);
    $content = json_encode([
        'title' => ucfirst(words($hash, 7)),
        'description' => ucfirst(words($hash + 1, 24)).'.',
        'tags' => array_map(fn ($i) => words($hash + $i, 1), range(1, 3 + $hash % 4)),
        'is_sensitive' => $hash % 17 === 0,
    ]);

    header('Content-Type: application/json');
    echo json_encode([
        'id' => 'chatcmpl-'.dechex($hash),
        'object' => 'chat.completion',
        'created' => 1790000000,
        'model' => 'stub-model',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => intdiv(strlen($body), 4), 'completion_tokens' => intdiv(strlen($content), 4), 'total_tokens' => intdiv(strlen($body) + strlen($content), 4)],
    ]);

    return;
}

if (str_starts_with($path, '/push/')) {
    usleep(($ms ?? 30) * 1000);
    http_response_code(201);

    return;
}

// Default: a JSON API call.
$ms ??= random_int(20, 80);
$kb = isset($_GET['kb']) ? max(0, min(1024, (int) $_GET['kb'])) : 4;
$seed = isset($_GET['seed']) ? (int) $_GET['seed'] : random_int(1, 1_000_000);

usleep($ms * 1000);

$items = [];
$size = 0;

for ($i = 0; $size < $kb * 1024; $i++) {
    $items[] = ['id' => $i, 'title' => 'Item '.($seed + $i), 'hash' => md5($seed.':'.$i)];
    $size += 80;
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'slept_ms' => $ms, 'items' => $items]);

/** $count deterministic pseudo-words for $key. */
function words(int $key, int $count): string
{
    static $vocabulary = ['project', 'news', 'market', 'coffee', 'football', 'weather', 'review', 'travel',
        'recipe', 'music', 'science', 'history', 'update', 'release', 'review', 'phone', 'city', 'report',
        'summer', 'lesson', 'section', 'idea', 'editor', 'garden', 'health', 'sport', 'garden', 'river',
        'price', 'energy', 'school', 'family', 'video', 'photo', 'story', 'event', 'local', 'global'];
    $out = [];
    $x = $key * 2654435761 % 4294967296;

    for ($i = 0; $i < $count; $i++) {
        $x = ($x * 1103515245 + 12345) % 2147483648;
        $out[] = $vocabulary[$x % count($vocabulary)];
    }

    return implode(' ', $out);
}
