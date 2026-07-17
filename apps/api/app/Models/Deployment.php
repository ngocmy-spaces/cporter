<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the deploy pipeline (docs/SPEC.md §5, §6).
 */
class Deployment extends Model
{
    protected $fillable = [
        'project_id',
        'release_id',
        'trigger',
        'status',
        'steps',
        'actor',
        'idempotency_key',
        'started_at',
        'finished_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'trigger' => 'api',
        'status' => 'queued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'trigger' => DeploymentTrigger::class,
            'steps' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Release, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}
