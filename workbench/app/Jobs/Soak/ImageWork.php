<?php

namespace App\Jobs\Soak;

use App\Models\MediaAsset;
use Bepsvpt\Blurhash\Facades\BlurHash;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;

/**
 * The media pool's CPU-bound work: draws a $size-pixel-wide source image,
 * encodes it as JPEG (the "download"), decodes it, resizes it to two renditions, encodes them as WebP
 * and JPEG, computes a blurhash, and writes the files (500 names, reused) to the local disk.
 */
class ImageWork extends SoakJob
{
    public $tries = 2;

    public function handle(): void
    {
        $random = $this->random();
        $manager = new ImageManager(new Driver);
        $width = $this->size;
        $height = intdiv($width * 2, 3);

        $source = $manager->createImage($width, $height)->fill(sprintf('#%06x', $random->getInt(0, 0xFFFFFF)));

        for ($i = 0; $i < 12; $i++) {
            $color = sprintf('#%06x', $random->getInt(0, 0xFFFFFF));
            $x = $random->getInt(0, $width);
            $y = $random->getInt(0, $height);
            $w = $random->getInt(40, 400);
            $h = $random->getInt(40, 300);
            $source->drawEllipse(function ($ellipse) use ($x, $y, $w, $h, $color) {
                $ellipse->at($x, $y);
                $ellipse->size($w, $h);
                $ellipse->background($color);
            });
        }

        $download = (string) $source->encodeUsingFormat(Format::JPEG, quality: 85);
        $image = $manager->decode($download);
        $slot = $this->seed % 500;
        $disk = Storage::disk('local');
        $assets = [];

        foreach ([640 => 'webp', 320 => 'jpg'] as $target => $format) {
            $rendition = (clone $image)->scaleDown(width: $target);
            $encoded = $format === 'webp'
                ? (string) $rendition->encodeUsingFormat(Format::WEBP, quality: 75)
                : (string) $rendition->encodeUsingFormat(Format::JPEG, quality: 80);
            $path = "media/{$slot}-{$target}.{$format}";
            $disk->put($path, $encoded);
            $assets[] = [$path, $rendition->width(), $rendition->height(), strlen($encoded), $format === 'webp' ? 'image/webp' : 'image/jpeg'];
        }

        $thumb = $disk->path("media/{$slot}-320.jpg");
        $hash = BlurHash::encode($thumb);

        foreach ($assets as [$path, $w, $h, $bytes, $mime]) {
            MediaAsset::query()->updateOrCreate(['path' => $path], [
                'width' => $w, 'height' => $h, 'bytes' => $bytes, 'mime' => $mime, 'blurhash' => $hash,
            ]);
        }
    }
}
