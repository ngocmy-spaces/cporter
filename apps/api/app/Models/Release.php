<?php

namespace App\Models;

use App\Enums\ReleaseState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One immutable release directory for a project (docs/SPEC.md §4.1, §5).
 */
class Release extends Model
{
    protected $fillable = [
        'project_id',
        'artifact_id',
        'version',
        'path',
        'state',
        'created_by',
        'activated_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'state' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => ReleaseState::class,
            'activated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Artifact, $this> */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(Artifact::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
