<?php

use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Middleware\EnsureApiScope;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\ReadScopeOrSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\AuthenticateSession;

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
            'apikey' => AuthenticateApiKey::class,
            'scope' => EnsureApiScope::class,
            'role' => EnsureRole::class,
            // Read endpoints shared by the SPA (session) and CI (read-scope API key) — see T5.4.
            'read.session_or_apikey' => ReadScopeOrSession::class,
        ]);

        // Track the auth password hash in the session so a user can invalidate their OTHER
        // sessions on password change (Auth::logoutOtherDevices — see AuthController). Any
        // session whose stored hash no longer matches is logged out on its next request.
        $middleware->web(append: [
            AuthenticateSession::class,
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
            fn (AuthenticationException $e) => response()->json(['message' => $e->getMessage()], 401)
        );
    })->create();
