<?php

namespace Database\Factories;

use App\Models\ApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiKey>
 *
 * NOTE: for tests that need the plaintext token, use ApiKeyService::generate() instead —
 * this factory only fabricates a stored record (hash of an unknown token).
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $secret = bin2hex(random_bytes(24));

        return [
            'name' => fake()->unique()->words(2, true),
            'prefix' => substr($secret, 0, 12),
            'hash' => hash('sha256', 'cpk_'.$secret),
            'scopes' => ['read'],
            'project_id' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
