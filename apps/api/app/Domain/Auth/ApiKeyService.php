<?php

namespace App\Domain\Auth;

use App\Models\ApiKey;
use DateTimeInterface;

/**
 * Issues and verifies CI API tokens (docs/SPEC.md §5, §12).
 *
 * Token format: `cpk_<48 hex>`. Only a SHA-256 hash is stored; the first 12 hex chars
 * are also stored as a non-secret `prefix` for fast lookup. The plaintext is returned
 * exactly once at creation.
 */
class ApiKeyService
{
    private const PREFIX = 'cpk_';

    private const PREFIX_LEN = 12;

    /**
     * @param  list<string>  $scopes
     * @return array{token: string, api_key: ApiKey}
     */
    public function generate(
        string $name,
        array $scopes = [],
        ?int $projectId = null,
        ?DateTimeInterface $expiresAt = null,
    ): array {
        $secret = bin2hex(random_bytes(24)); // 48 hex chars
        $token = self::PREFIX.$secret;

        $apiKey = ApiKey::create([
            'name' => $name,
            'prefix' => substr($secret, 0, self::PREFIX_LEN),
            'hash' => $this->hash($token),
            'scopes' => array_values($scopes),
            'project_id' => $projectId,
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'api_key' => $apiKey];
    }

    /**
     * Resolve a plaintext token to an active ApiKey, or null if invalid/revoked/expired.
     */
    public function authenticate(?string $token): ?ApiKey
    {
        if (! is_string($token) || ! str_starts_with($token, self::PREFIX)) {
            return null;
        }

        $secret = substr($token, strlen(self::PREFIX));
        $prefix = substr($secret, 0, self::PREFIX_LEN);

        $apiKey = ApiKey::where('prefix', $prefix)->first();

        if ($apiKey === null || ! hash_equals($apiKey->hash, $this->hash($token))) {
            return null;
        }

        return $apiKey->isActive() ? $apiKey : null;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
