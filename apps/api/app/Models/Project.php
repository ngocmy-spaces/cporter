<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'disk_usage',
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
            'hooks' => 'array',
            'keep_releases' => 'integer',
            'disk_usage' => 'integer',
        ];
    }

    /**
     * shared_paths is stored as a JSON list of {path, type} objects (type: 'file'|'dir'),
     * telling the deploy engine whether a missing shared entry should be seeded as a file
     * or a directory (docs/SPEC.md §6 step 8). Legacy bare-string entries (e.g. ".env")
     * are accepted and normalized to type 'dir' — the pre-typed default — on both read and
     * write, so old rows and old API clients keep working without a migration.
     */
    protected function sharedPaths(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => self::normalizeSharedPaths(json_decode($value ?? '[]', true) ?: []),
            set: fn ($value): string => (string) json_encode(self::normalizeSharedPaths($value)),
        );
    }

    /**
     * @param  mixed  $raw
     * @return list<array{path: string, type: string}>
     */
    public static function normalizeSharedPaths($raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (is_string($item)) {
                $path = trim($item);
                $type = 'dir';
            } elseif (is_array($item)) {
                $path = trim((string) ($item['path'] ?? ''));
                $type = ($item['type'] ?? 'dir') === 'file' ? 'file' : 'dir';
            } else {
                continue;
            }

            if ($path === '') {
                continue;
            }

            $out[] = ['path' => $path, 'type' => $type];
        }

        return $out;
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
