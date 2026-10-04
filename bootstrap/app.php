<?php

use App\Http\Middleware\AbsoluteSessionLifetime;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            AbsoluteSessionLifetime::class,
        ]);

        // Trust the Docker-local reverse proxy (Nginx Proxy Manager
        // terminates TLS on 443 and forwards plain HTTP to 127.0.0.1:8089).
        // Honour its X-Forwarded-* headers so Laravel builds https URLs,
        // route() points at the public host, and the real client IP is
        // visible to rate-limiters and audit logs instead of the bridge
        // gateway (172.x.x.x).
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
