<?php

namespace App\Http\Middleware;

use App\Domain\Auth\ApiKeyService;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dual-auth read guard for the few endpoints shared by the admin SPA and CI (docs/SPEC.md §7, §12,
 * §20.1). A request authenticates EITHER with a `read`-scope API key (bearer token) OR the admin
 * same-origin session (web guard). This lets one route serve both surfaces without registering the
 * same URI twice (a duplicate would shadow the first). On the API-key path the resolved key is
 * stashed on `api_key` so the controller can still enforce its project binding.
 */
class ReadScopeOrSession
{
    public function __construct(private readonly ApiKeyService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        // CI: a bearer token must resolve to a valid key carrying the `read` scope.
        if ($request->bearerToken() !== null) {
            $apiKey = $this->service->authenticate($request->bearerToken());
            if (! $apiKey instanceof ApiKey) {
                return response()->json(['error' => 'Invalid or missing API key.'], 401);
            }
            if (! $apiKey->hasScope('read')) {
                return response()->json(['error' => 'Insufficient scope.', 'required' => 'read'], 403);
            }
            $apiKey->markUsed();
            $request->attributes->set('api_key', $apiKey);

            return $next($request);
        }

        // Admin SPA: fall back to the same-origin session (the surrounding `web` group started it).
        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        return response()->json(['error' => 'Unauthenticated.'], 401);
    }
}
