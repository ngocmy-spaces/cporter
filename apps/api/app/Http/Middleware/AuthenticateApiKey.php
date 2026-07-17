<?php

namespace App\Http\Middleware;

use App\Domain\Auth\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates CI requests via `Authorization: Bearer cpk_...` (docs/SPEC.md §7, §12).
 * On success the resolved ApiKey is stashed on the request attributes as `api_key`.
 */
class AuthenticateApiKey
{
    public function __construct(private readonly ApiKeyService $service) {}

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $this->service->authenticate($request->bearerToken());

        if ($apiKey === null) {
            return response()->json(['error' => 'Invalid or missing API key.'], 401);
        }

        $apiKey->markUsed();
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }
}
