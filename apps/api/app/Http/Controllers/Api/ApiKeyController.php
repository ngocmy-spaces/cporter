<?php

namespace App\Http\Controllers\Api;

use App\Domain\Auth\ApiKeyService;
use App\Enums\ApiScope;
use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin management of CI API keys (docs/SPEC.md §12, §13). Admin session required.
 * The plaintext token is returned exactly once, on create.
 */
class ApiKeyController extends Controller
{
    public function __construct(private readonly ApiKeyService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => ApiKey::query()->latest()->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'scopes' => ['array'],
            'scopes.*' => [Rule::enum(ApiScope::class)],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $result = $this->service->generate(
            $data['name'],
            $data['scopes'] ?? [],
            $data['project_id'] ?? null,
            isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
        );

        return response()->json([
            'data' => $result['api_key'],
            'token' => $result['token'], // shown once — store it now
        ], 201);
    }

    public function destroy(ApiKey $apiKey): JsonResponse
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return response()->json(['data' => true]);
    }
}
