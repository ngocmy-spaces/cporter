<?php

namespace App\Models;

use App\Enums\ArtifactStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A build artifact (.zip) uploaded from CI, before/after extraction (docs/SPEC.md §5, §6).
 *
 * The zip is consumed once, at extraction. After the deploy is stable the file is reclaimed
 * (see ArtifactPruner): storage_path is nulled and pruned_at stamped, but the row is kept so
 * size / sha256 / filename remain available for the summary + charts.
 */
class Artifact extends Model
{
    protected $fillable = [
        'project_id',
        'filename',
        'size',
        'sha256',
        'storage_path',
        'status',
        'uploaded_at',
        'pruned_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'size' => 0,
        'status' => 'pending',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'status' => ArtifactStatus::class,
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'pruned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }
}
