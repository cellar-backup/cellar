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
        // Trust proxies configured via TRUSTED_PROXIES env var.
        // Set to '*' to trust all (common in Docker/K8s), or comma-separated IPs.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
