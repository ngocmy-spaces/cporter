<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;

/**
 * A managed project = one cPanel domain folder cPorter deploys to (docs/SPEC.md §5).
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, SoftDeletes;

    /** Deploy stages the engine runs hooks for, in order (docs/SPEC.md §9). */
    public const HOOK_STAGES = ['pre_activate', 'post_activate'];

    protected $fillable = [
        'name',
        'slug',
        'base_path',
        'type',
        'docroot_subpath',
        'keep_releases',
        'disk_usage',
        'releases_disk_usage',
        'disk_usage_status',
        'disk_usage_started_at',
        'disk_usage_calculated_at',
        'shared_disk_usage',
        'health_check_url',
        'shared_paths',
        'hooks',
        'env_vars',
        'status',
    ];

    /**
     * Never leak env-var values through the default model serialization (show()/index()), which
     * viewers can read — secrets are exposed only via the admin-only /projects/{project}/env
     * endpoint. `$hidden` affects array/JSON output only; `$project->env_vars` still works in PHP
     * for the deploy engine.
     *
     * @var list<string>
     */
    protected $hidden = [
        'env_vars',
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
            'keep_releases' => 'integer',
            'disk_usage' => 'integer',
            'releases_disk_usage' => 'integer',
            'disk_usage_started_at' => 'datetime',
            'disk_usage_calculated_at' => 'datetime',
            'shared_disk_usage' => 'array',
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

    /**
     * hooks is a JSON object of {stage: [command, …]} the deploy engine runs around activation
     * (docs/SPEC.md §9). Only the known stages ({@see self::HOOK_STAGES}) are kept; each command
     * is trimmed and blanks are dropped, so a UI can send padded/empty rows without persisting
     * junk. Empty stages are omitted entirely, so `hooks` is `{}` when nothing is configured.
     */
    protected function hooks(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => self::normalizeHooks(json_decode($value ?? '{}', true) ?: []),
            // Cast to object so an empty map serializes as `{}`, not `[]`, while the inner
            // command lists stay JSON arrays (JSON_FORCE_OBJECT would wrongly objectify those).
            set: fn ($value): string => (string) json_encode((object) self::normalizeHooks($value)),
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<string, list<string>>
     */
    public static function normalizeHooks($raw): array
    {
        $out = [];

        foreach (self::HOOK_STAGES as $stage) {
            $commands = is_array($raw) ? ($raw[$stage] ?? []) : [];
            if (! is_array($commands)) {
                continue;
            }

            $clean = [];
            foreach ($commands as $cmd) {
                if (is_string($cmd) && trim($cmd) !== '') {
                    $clean[] = trim($cmd);
                }
            }

            if ($clean !== []) {
                $out[$stage] = $clean;
            }
        }

        return $out;
    }

    /**
     * env_vars is an ordered JSON list of {key, value} pairs cPorter renders into shared/.env on
     * deploy (docs/SPEC.md §9). Because these are secrets, the value is stored ENCRYPTED at rest
     * via Laravel's Crypt (APP_KEY) — the column holds ciphertext, not JSON. Keys are trimmed,
     * blank-key rows dropped, and duplicate keys de-duped (last wins) so a UI/import can send
     * padded rows without persisting junk. This is the app's only reversible-secret store.
     */
    protected function envVars(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => self::normalizeEnvVars(
                $value !== null && $value !== '' ? json_decode(Crypt::decryptString($value), true) : []
            ),
            set: fn ($value): string => Crypt::encryptString(
                (string) json_encode(self::normalizeEnvVars($value))
            ),
        );
    }

    /**
     * @param  mixed  $raw
     * @return list<array{key: string, value: string}>
     */
    public static function normalizeEnvVars($raw): array
    {
        $out = [];

        foreach (is_array($raw) ? $raw : [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            // De-dupe by key, last value wins; preserve first-seen order.
            $out[$key] = (string) ($item['value'] ?? '');
        }

        return array_map(
            fn (string $key, string $value): array => ['key' => $key, 'value' => $value],
            array_keys($out),
            array_values($out),
        );
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
