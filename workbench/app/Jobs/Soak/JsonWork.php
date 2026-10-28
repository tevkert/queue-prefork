<?php

namespace App\Jobs\Soak;

/** Builds an array of about $size MB as JSON, encodes and decodes it. */
class JsonWork extends SoakJob
{
    public function handle(): void
    {
        $random = $this->random();
        $count = intdiv($this->size * 1024 * 1024, 250);
        $data = [];

        for ($i = 0; $i < $count; $i++) {
            $data[] = [
                'id' => $i,
                'title' => 'Item '.$i,
                'slug' => bin2hex($random->getBytes(8)),
                'words' => $random->getInt(500, 9000),
                'tags' => ['alpha', 'beta', $i % 17],
                'body' => bin2hex($random->getBytes(64)),
            ];
        }

        $json = json_encode($data, JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (count($decoded) !== $count) {
            throw new \RuntimeException('JsonWork: round trip lost rows');
        }

        md5($json);
    }
}
