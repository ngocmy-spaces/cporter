<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A managed project = one cPanel domain folder cPorter deploys to (docs/SPEC.md §5).
 */
class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'base_path',
        'type',
        'docroot_subpath',
        'php_binary',
        'keep_releases',
        'health_check_url',
        'shared_paths',
        'hooks',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'type' => 'static',
        'status' => 'active',
        'keep_releases' => 5,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProjectType::class,
            'status' => ProjectStatus::class,
            'shared_paths' => 'array',
            'hooks' => 'array',
            'keep_releases' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return HasMany<Release, $this> */
    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    /** @return HasMany<Artifact, $this> */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }

    /** @return HasMany<Deployment, $this> */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    /** @return HasMany<ApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }
}
