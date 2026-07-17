<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated API key carries a required scope (docs/SPEC.md §12).
 * Use after `apikey`, e.g. `->middleware(['apikey', 'scope:deploy'])`.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey instanceof ApiKey || ! $apiKey->hasScope($scope)) {
            return response()->json(['error' => 'Insufficient scope.', 'required' => $scope], 403);
        }

        return $next($request);
    }
}
