<?php

use App\Http\Middleware\AuthenticateMerchant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'auth.merchant' => AuthenticateMerchant::class,
        ]);

        // ThrottleRequests is in Laravel's priority list, so without this the framework would
        // move it ahead of our auth middleware and the limiter would never see the merchant.
        $middleware->prependToPriorityList(
            before: ThrottleRequests::class,
            prepend: AuthenticateMerchant::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API clients always get JSON errors (422/401/409/429), whatever Accept header they send.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
