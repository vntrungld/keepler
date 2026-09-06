<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // The external scheduler has no session to carry a CSRF token; these
        // routes authenticate with the X-Cron-Token header instead.
        $middleware->validateCsrfTokens(except: [
            'cron/*',
        ]);

        // Production sits behind a load balancer that terminates TLS and
        // forwards plain HTTP, so X-Forwarded-Proto is the only signal that the
        // original request was https. Without trusting it every generated URL
        // is http, and the browser blocks the assets on an https page as mixed
        // content. Any proxy is trusted because the container is reachable
        // only through the platform's load balancer.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
