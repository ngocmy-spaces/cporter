<?php

namespace App\Models;

use App\Enums\ArtifactStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A build artifact (.zip) uploaded from CI, before/after extraction (docs/SPEC.md §5, §6).
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
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
