<?php

namespace App\Support;

use Laravel\Nightwatch\Location;
use Sentry\Options;
use Sentry\Serializer\RepresentationSerializer;
use Sentry\StacktraceBuilder;
use Spatie\FlareClient\Flare;
use Spatie\FlareClient\FlareConfig;
use Throwable;

/**
 * What three error trackers make of an exception's stack trace, through their own code, set up as their
 * Laravel integrations set them up, with nothing sent. Each decides by path which frames are the app's
 * own code, and a forked worker's trace ends in the launcher it was forked from, not in `artisan`:
 *
 * - Flare drops every frame above the first one it counts as the app's (ReportFactory::buildStacktrace);
 * - Sentry marks frames in-app (sentry-laravel's in_app_exclude) and groups by them;
 * - Nightwatch places an exception at its first frame outside vendor/ (Location::forException).
 */
class ErrorTrackers
{
    private static ?Flare $flare = null;

    public static function record(Throwable $e): void
    {
        $at = fn (string $file, ?int $line): string => str_replace(base_path().'/', '', $file).':'.$line;

        self::$flare ??= Flare::make(FlareConfig::make('workbench')->useDefaults()->applicationPath(base_path()));
        $flare = self::$flare->createReport($e)->toArray()['stacktrace'];

        // sentry-laravel's defaults (ServiceProvider::configureAndRegisterClient)
        $options = new Options(['prefixes' => [base_path()], 'in_app_exclude' => [base_path('vendor'), base_path('artisan')]]);
        $sentry = (new StacktraceBuilder($options, new RepresentationSerializer($options)))->buildFromException($e)->getFrames();

        [$file, $line] = (new Location(base_path(), public_path()))->forException($e);

        JobResult::record('ErrorTrackers', [
            'thrown_at' => $at($e->getFile(), $e->getLine()),
            'flare_first_frame' => $at($flare[0]['file'], $flare[0]['lineNumber']),
            'flare_app_frames' => array_map(fn (array $frame) => $at($frame['file'], $frame['lineNumber']), array_values(array_filter($flare, fn (array $frame) => $frame['isApplicationFrame']))),
            'sentry_in_app' => array_map(fn ($frame) => $at($frame->getAbsoluteFilePath(), $frame->getLine()), array_values(array_filter($sentry, fn ($frame) => $frame->isInApp()))),
            'nightwatch_location' => $at(str_starts_with($file, '/') ? $file : base_path($file), $line),
        ]);
    }
}
