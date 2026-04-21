<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Using Sanctum bearer tokens (not cookie/session auth),
        // so we don't need the stateful SPA middleware (which requires CSRF).
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
