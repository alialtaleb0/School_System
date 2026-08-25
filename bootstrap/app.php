<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // ✅ Sanctum — EnsureFrontendRequestsAreStateful removed: mobile app uses token auth, not SPA cookies

        // ✅ UpdateLastSeen
        $middleware->appendToGroup('api', \App\Http\Middleware\UpdateLastSeen::class);

        // ✅ SetLocale (ترجمة الاستجابات حسب Accept-Language)
        $middleware->appendToGroup('api', \App\Http\Middleware\SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ✅ API-only app: unauthenticated requests get clean JSON 401
        //    instead of trying to redirect to a non-existent 'login' route
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => __('Unauthenticated.')], 401);
            }
        });
    })->create();
