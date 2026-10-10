<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Performance: the Boosthis kit runs as prepended GLOBAL middleware, so it
        // executes on every request (including every /api/properties call the public
        // homepage makes) and added ~4s of blocking work before each response.
        // It is a telemetry/monitoring SDK, not application logic. Keep it wired
        // so nothing breaks, but honour its absolute kill switch: set
        // BOOSTHIS_DISABLED=true in the container environment to no-op it.
        // (It must be a real env var — with bootstrap/cache/config.php present
        // Laravel skips loading .env, so a .env entry would not reach getenv().)
        $middleware->prepend(\Boosthis\LaravelMiddleware::class);
        $middleware->statefulApi();
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'owner' => \App\Http\Middleware\OwnerMiddleware::class,
            'tenant' => \App\Http\Middleware\TenantMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
