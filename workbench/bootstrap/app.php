<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\Console\Exception\CommandNotFoundException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // The fails-in-vendor fixture: what error trackers would report (App\Support\ErrorTrackers).
        $exceptions->report(function (CommandNotFoundException $e): void {
            if (str_contains($e->getMessage(), 'fixtures:no-such-command')) {
                App\Support\ErrorTrackers::record($e);
            }
        });
    })->create();
