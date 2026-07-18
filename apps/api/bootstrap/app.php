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
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // CI-facing API-key auth + scope enforcement (docs/SPEC.md §12).
        // Admin auth is a same-origin session (web guard) — those routes opt into the
        // `web` middleware group explicitly in routes/api.php.
        $middleware->alias([
            'apikey' => \App\Http\Middleware\AuthenticateApiKey::class,
            'scope' => \App\Http\Middleware\EnsureApiScope::class,
        ]);

        // API-only app: never redirect guests to a (non-existent) `login` route.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // This is an API: always render errors as JSON for /api/* ...
        $exceptions->shouldRenderJsonWhen(
            fn ($request) => $request->is('api/*') || $request->expectsJson()
        );

        // ...and always answer unauthenticated requests with a JSON 401 (this backend is
        // API-only; there is no web login page to redirect to).
        $exceptions->render(
            fn (\Illuminate\Auth\AuthenticationException $e) => response()->json(['message' => $e->getMessage()], 401)
        );
    })->create();
