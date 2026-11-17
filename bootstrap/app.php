<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'role' => \App\Http\Middleware\RoleMiddleware::class,
    ]);
    // HTTPS behind a proxy: locally only cloudflared on this machine (127.0.0.1) is trusted, so nobody outside can
    // spoof X-Forwarded-*. On Render.com every request comes through its load balancer: set TRUSTED_PROXIES=*.
    $proxies = (string) env('TRUSTED_PROXIES', '127.0.0.1,::1');
    $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
    // X-Frame-Options, nosniff, CSP, HSTS (on HTTPS) ... on every web response
    $middleware->web(append: [\App\Http\Middleware\SecurityHeaders::class]);
    // once a day, after the response: delete expired assessment attachments (no cron on Render free)
    $middleware->web(append: [\App\Http\Middleware\PruneAttachmentsDaily::class]);
})
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
