<?php

namespace App\Models;

use App\Enums\ApiScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hashed API token for CI (docs/SPEC.md §5, §12). Issued/verified by ApiKeyService.
 */
class ApiKey extends Model
{
    /** @use HasFactory<\Database\Factories\ApiKeyFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'prefix',
        'hash',
        'scopes',
        'project_id',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** Not revoked and not expired. */
    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /** True if the key carries the given scope (or the `admin` super-scope). */
    public function hasScope(ApiScope|string $scope): bool
    {
        $needle = $scope instanceof ApiScope ? $scope->value : $scope;
        $scopes = $this->scopes ?? [];

        return in_array(ApiScope::Admin->value, $scopes, true) || in_array($needle, $scopes, true);
    }

    public function markUsed(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }
}
