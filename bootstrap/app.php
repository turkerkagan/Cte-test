<?php

use App\Http\Middleware\EnsurePortalSession;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MeasurePortalRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        if (env('CTE_TRUST_PROXY', false)) {
            $middleware->trustProxies(at: '*');
        }
        $middleware->web(append: [MeasurePortalRequests::class, HandleInertiaRequests::class]);
        $middleware->alias(['portal.session' => EnsurePortalSession::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('portal'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Domain exception handling is added with the corresponding module.
    })->create();
