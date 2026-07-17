<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A hashed API token for CI (docs/SPEC.md §5, §12). Generation/validation logic is T0.6.
 */
class ApiKey extends Model
{
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
}
