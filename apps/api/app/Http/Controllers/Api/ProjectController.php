<?php

namespace App\Http\Controllers\Api;

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Audit\AuditLogger;
use App\Domain\Deploy\EnvFileRenderer;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
use App\Enums\ReleaseState;
use App\Http\Controllers\Controller;
use App\Jobs\PurgeProjectJob;
use App\Jobs\RecomputeDiskUsageJob;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Admin management of projects (docs/SPEC.md §5, §13). Admin session required.
 * A project's base_path MUST lie within the configured jail (docs/SPEC.md §12).
 */
class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Project::query()->latest();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', '%'.$search.'%')
                    ->orWhere('slug', 'like', '%'.$search.'%')
                    ->orWhere('base_path', 'like', '%'.$search.'%');
            });
        }

        $status = (string) $request->query('status', '');
        if (in_array($status, ['active', 'disabled', 'deleting'], true)) {
            $query->where('status', $status);
        }

        // Opt-in pagination: with a page/per_page param, return a paginated {data, meta}
        // envelope; otherwise the full list, preserving the shape other consumers (dashboard,
        // token project-scoping dropdown) rely on.
        if ($request->has('page') || $request->has('per_page')) {
            $perPage = min(max((int) $request->query('per_page', 20), 1), 100);
            $page = $query->paginate($perPage)->withQueryString();

            return response()->json([
                'data' => $page->items(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function show(Project $project): JsonResponse
    {
        // Summary bits the detail page's Overview needs up front, so the per-tab
        // deployments / releases / activity lists can each load lazily (only when their
        // tab is opened) instead of all firing on page entry.
        $activeRelease = $project->releases()
            ->where('state', ReleaseState::Active->value)
            ->latest('activated_at')
            ->first();

        $lastDeployment = $project->deployments()->latest()->first();

        $data = $project->toArray();
        $data['active_release'] = $activeRelease?->only(['id', 'version', 'activated_at']);
        $data['last_deployment'] = $lastDeployment?->only(['id', 'status', 'created_at']);
        // Lets the UI keep freezing identity fields (type/base_path) once releases exist without
        // having to load the full — now lazily fetched — releases list.
        $data['release_count'] = $project->releases()->count();

        return response()->json(['data' => $data]);
    }

    /**
     * Kick off a disk-usage recompute for one project (docs/SPEC.md §11). Idempotent: if a
     * recompute is already in flight it is left alone (repeat clicks / page reloads are safe),
     * except when the prior run is stale (worker likely died) — then it is re-dispatched.
     * Returns 202 with the project so the UI can poll `disk_usage_status` to completion.
     */
    public function recomputeDiskUsage(Project $project): JsonResponse
    {
        $stale = $project->disk_usage_started_at !== null
            && $project->disk_usage_started_at->lt(now()->subMinutes(10));

        if ($project->disk_usage_status !== 'running' || $stale) {
            $project->forceFill([
                'disk_usage_status' => 'running',
                'disk_usage_started_at' => now(),
            ])->save();

            RecomputeDiskUsageJob::dispatch($project);
        }

        return response()->json(['data' => $project->fresh()], 202);
    }

    public function store(Request $request, PathJail $jail, StorageAdapter $storage): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', 'unique:projects,slug'],
            'base_path' => ['required', 'string', 'max:1024'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'docroot_subpath' => ['nullable', 'string', 'max:255'],
            'keep_releases' => ['nullable', 'integer', 'min:1', 'max:50'],
            'health_check_url' => ['nullable', 'url'],
            'shared_paths' => ['array'],
            'shared_paths.*' => [$this->sharedPathRule()],
            'hooks' => ['nullable', 'array', $this->hooksRule()],
        ]);

        $result = $this->persistProject($data, $jail, $storage, 'project.created');

        return response()->json(['data' => $result['project'], 'preflight' => $result['preflight']], 201);
    }

    /**
     * Duplicate an existing project's configuration into a new project (docs/SPEC.md §5). Copies
     * type/docroot/keep_releases/health_check_url/shared_paths/hooks/env_vars; the caller supplies
     * a new name + base_path (+ optional slug). No releases/deployments/artifacts are copied — the
     * clone is a fresh project that starts `active` with no live release until its first deploy.
     * `shared_paths` of type `file` are copied as config; their contents are NOT — preflight will
     * flag them as missing on the new folder so the operator can seed them.
     */
    public function clone(Request $request, Project $project, PathJail $jail, StorageAdapter $storage): JsonResponse
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', 'unique:projects,slug'],
            'base_path' => ['required', 'string', 'max:1024'],
        ]);

        $data = [
            'name' => $input['name'],
            'slug' => $input['slug'] ?? null,
            'base_path' => $input['base_path'],
            'type' => $project->type,
            'docroot_subpath' => $project->docroot_subpath,
            'keep_releases' => $project->keep_releases,
            'health_check_url' => $project->health_check_url,
            'shared_paths' => $project->shared_paths,
            'hooks' => $project->hooks,
            'env_vars' => $project->env_vars,
            'status' => ProjectStatus::Active,
        ];

        $result = $this->persistProject($data, $jail, $storage, 'project.cloned', [
            'source_slug' => $project->slug,
            'source_id' => $project->id,
        ]);

        return response()->json(['data' => $result['project'], 'preflight' => $result['preflight']], 201);
    }

    /**
     * Shared create path for store() + clone(): the base_path must be inside the jail and not
     * already used by another live project (two projects deploying to one folder would clash);
     * assign a unique slug, persist, audit, and scaffold + probe host readiness so setup errors
     * surface now rather than at first deploy. Lenient: the project is created regardless — the
     * preflight report tells the operator what (if anything) still needs a manual cPanel-side fix.
     *
     * @param  array<string, mixed>  $data  validated attributes (must include name + base_path)
     * @param  array<string, mixed>  $auditMeta  extra audit metadata merged onto {slug}
     * @return array{project: Project, preflight: array<string, mixed>}
     */
    private function persistProject(array $data, PathJail $jail, StorageAdapter $storage, string $auditAction, array $auditMeta = []): array
    {
        if (! $jail->isInside($data['base_path'])) {
            throw ValidationException::withMessages([
                'base_path' => 'base_path must be within an allowed base path (CPORTER_ALLOWED_BASE_PATHS).',
            ]);
        }

        if (Project::query()->where('base_path', $data['base_path'])->exists()) {
            throw ValidationException::withMessages([
                'base_path' => 'Another project already uses this base_path.',
            ]);
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']));

        $project = Project::create($data);

        app(AuditLogger::class)->record($auditAction, $project, ['slug' => $project->slug] + $auditMeta);

        $preflight = $this->runPreflight($project, $storage);

        return ['project' => $project->fresh(), 'preflight' => $preflight];
    }

    /**
     * Ensure the on-disk scaffold and report on host readiness (docs/SPEC.md §11). Idempotent —
     * safe to re-run from the project page after fixing a warning (e.g. creating shared/.env).
     * Creates missing releases/ + shared/ dirs, probes symlink support, and flags anything the
     * operator must still do by hand (missing shared files, the domain's Document Root).
     */
    public function preflight(Project $project, StorageAdapter $storage): JsonResponse
    {
        $report = $this->runPreflight($project, $storage);

        // Audit only the explicit re-check; on create, `project.created` already covers it.
        app(AuditLogger::class)->record('project.preflight', $project, [
            'slug' => $project->slug,
            'ok' => $report['ok'],
        ]);

        return response()->json(['data' => $report]);
    }

    /**
     * @return array{ok: bool, base_path: string, checks: list<array{key: string, label: string, status: string, detail: string}>}
     */
    private function runPreflight(Project $project, StorageAdapter $storage): array
    {
        $report = $storage->preflight($project->base_path, $project->shared_paths ?? []);

        // When cPorter manages env vars but shared/.env was hand-created, deploys skip writing it —
        // surface that conflict here rather than mid-deploy (docs/SPEC.md §9).
        if (($project->env_vars ?? []) !== []) {
            $state = $storage->sharedFileState($this->sharedDir($project), '.env', EnvFileRenderer::MARKER);
            if ($state['exists'] && ! $state['managed']) {
                $report['checks'][] = [
                    'key' => 'env_file',
                    'label' => 'Managed .env',
                    'status' => 'warning',
                    'detail' => 'shared/.env exists but is not managed by cPorter — env vars will be skipped on deploy '
                        .'until you take it over from the Environment tab.',
                ];
            }
        }

        // Document Root is a cPanel vhost concern cPorter can't configure — always a manual reminder.
        $report['checks'][] = $this->docrootCheck($project);

        return $report;
    }

    /** shared/ dir for a project — mirrors DeployEngine's convention (base_path + '/shared'). */
    private function sharedDir(Project $project): string
    {
        return rtrim($project->base_path, '/').'/shared';
    }

    /**
     * @return array{key: string, label: string, status: string, detail: string}
     */
    private function docrootCheck(Project $project): array
    {
        $sub = trim((string) $project->docroot_subpath, '/');
        $target = rtrim($project->base_path, '/').'/current'.($sub !== '' ? '/'.$sub : '');

        return [
            'key' => 'docroot',
            'label' => 'Document Root',
            'status' => 'manual',
            'detail' => "In cPanel, point the domain's Document Root to {$target} — cPorter cannot configure the vhost.",
        ];
    }

    /**
     * Partial update of a project's config (docs/SPEC.md §5). PATCH semantics: only the
     * fields present in the request are validated and applied. `status` doubles as the
     * enable/disable toggle — a `disabled` project rejects new deploys (DeploymentController).
     *
     * Identity/location fields (`slug`, `base_path`, `type`) are frozen once physical releases
     * exist, since `releases/` and the `current` symlink are anchored to them (docs/SPEC.md §20.5).
     */
    public function update(Request $request, Project $project, PathJail $jail): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'alpha_dash', 'max:255', Rule::unique('projects', 'slug')->ignore($project->id)],
            'base_path' => ['sometimes', 'required', 'string', 'max:1024'],
            'type' => ['sometimes', 'required', Rule::enum(ProjectType::class)],
            'docroot_subpath' => ['sometimes', 'nullable', 'string', 'max:255'],
            'keep_releases' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'health_check_url' => ['sometimes', 'nullable', 'url'],
            'shared_paths' => ['sometimes', 'array'],
            'shared_paths.*' => [$this->sharedPathRule()],
            'hooks' => ['sometimes', 'nullable', 'array', $this->hooksRule()],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
        ]);

        if ($project->releases()->exists()) {
            foreach (['slug', 'base_path', 'type'] as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== (string) $project->getRawOriginal($field)) {
                    throw ValidationException::withMessages([
                        $field => "Cannot change {$field} once the project has releases.",
                    ]);
                }
            }
        }

        if (array_key_exists('base_path', $data)) {
            if (! $jail->isInside($data['base_path'])) {
                throw ValidationException::withMessages([
                    'base_path' => 'base_path must be within an allowed base path (CPORTER_ALLOWED_BASE_PATHS).',
                ]);
            }
            if (! is_dir($data['base_path'])) {
                @mkdir($data['base_path'], 0775, true);
            }
        }

        $project->fill($data);
        $changed = array_keys($project->getDirty());
        $project->save();

        app(AuditLogger::class)->record('project.updated', $project, [
            'slug' => $project->slug,
            'changed' => $changed,
        ]);

        return response()->json(['data' => $project->fresh()]);
    }

    /**
     * Read a project's managed environment variables (docs/SPEC.md §9). Admin-only — returns the
     * DECRYPTED values, so this must never be exposed to viewers or bundled into show()/index().
     * Also reports the on-disk shared/.env state so the UI can show the take-over warning/action.
     */
    public function env(Project $project, StorageAdapter $storage): JsonResponse
    {
        return response()->json(['data' => $this->envPayload($project, $storage)]);
    }

    /**
     * Replace a project's managed env vars (docs/SPEC.md §9). Values are stored encrypted at rest
     * (see Project::envVars). Keys must be POSIX-shell-safe and unique; blank-key rows are dropped
     * by the model. On the next deploy cPorter renders these into shared/.env.
     */
    public function updateEnv(Request $request, Project $project, StorageAdapter $storage): JsonResponse
    {
        $data = $request->validate([
            'env_vars' => ['present', 'array'],
            'env_vars.*' => ['array'],
            'env_vars.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'env_vars.*.value' => ['nullable', 'string', 'max:32768'],
        ], [
            'env_vars.*.key.regex' => 'Each key must start with a letter or underscore and contain only letters, digits, and underscores.',
        ]);

        $this->assertUniqueEnvKeys($data['env_vars']);

        $project->env_vars = $data['env_vars'];
        $project->save();

        // Audit key NAMES + count only — never the secret values.
        app(AuditLogger::class)->record('project.env_updated', $project, [
            'slug' => $project->slug,
            'keys' => array_map(fn (array $v): string => $v['key'], $project->env_vars),
            'count' => count($project->env_vars),
        ]);

        return response()->json(['data' => $this->envPayload($project, $storage)]);
    }

    /**
     * Force-write shared/.env from the current env vars, taking over a hand-created (unmanaged)
     * file (docs/SPEC.md §9). Establishes cPorter ownership (stamps the managed marker) so future
     * deploys overwrite normally; it does NOT itself redeploy — the running release picks the file
     * up on the next deploy, which the returned `message` tells the operator.
     */
    public function adoptEnvFile(Project $project, StorageAdapter $storage): JsonResponse
    {
        $storage->writeSharedFile(
            $this->sharedDir($project),
            '.env',
            EnvFileRenderer::render($project->env_vars ?? []),
            EnvFileRenderer::MARKER,
            force: true,
        );

        app(AuditLogger::class)->record('project.env_adopted', $project, ['slug' => $project->slug]);

        return response()->json([
            'data' => $this->envPayload($project, $storage),
            'message' => 'shared/.env is now managed by cPorter. Trigger a deploy to apply it to the live release.',
        ]);
    }

    /**
     * @return array{vars: list<array{key: string, value: string}>, file: array{exists: bool, managed: bool}}
     */
    private function envPayload(Project $project, StorageAdapter $storage): array
    {
        return [
            'vars' => $project->fresh()->env_vars,
            'file' => $storage->sharedFileState($this->sharedDir($project), '.env', EnvFileRenderer::MARKER),
        ];
    }

    /**
     * The model de-dupes silently (last wins), but for the editor we surface a duplicate as a
     * field error so the operator notices rather than losing a row.
     *
     * @param  array<int, mixed>  $envVars
     */
    private function assertUniqueEnvKeys(array $envVars): void
    {
        $seen = [];
        foreach ($envVars as $i => $var) {
            $key = is_array($var) ? trim((string) ($var['key'] ?? '')) : '';
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "env_vars.{$i}.key" => "Duplicate key \"{$key}\".",
                ]);
            }
            $seen[$key] = true;
        }
    }

    /**
     * Soft-delete a project (docs/SPEC.md §5), optionally reclaiming its disk. The `purge` level
     * decides how much of the on-disk footprint is removed:
     *   - `none`     → unmanage only: the DB row is soft-deleted immediately (hidden), files untouched.
     *   - `releases` → delete releases/ and the `current` symlink, keep shared/ (user data such as .env, uploads).
     *   - `all`      → delete the entire project base_path folder.
     *
     * With a purge level the disk work runs async (PurgeProjectJob): the project enters the
     * `deleting` status (still visible, deploys blocked) and is soft-deleted once the job finishes.
     */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        $purge = $request->validate([
            'purge' => ['nullable', Rule::in(['none', 'releases', 'all'])],
        ])['purge'] ?? 'none';

        if ($project->status === ProjectStatus::Deleting) {
            return response()->json(['error' => 'Project deletion is already in progress.'], 409);
        }

        if ($purge === 'none') {
            $project->delete();

            app(AuditLogger::class)->record('project.deleted', $project, [
                'slug' => $project->slug,
                'purge' => 'none',
            ]);

            return response()->json(['data' => null]);
        }

        $project->forceFill(['status' => ProjectStatus::Deleting])->save();
        PurgeProjectJob::dispatch($project, $purge);

        app(AuditLogger::class)->record('project.deleting', $project, [
            'slug' => $project->slug,
            'purge' => $purge,
        ]);

        return response()->json(['data' => $project->fresh()], 202);
    }

    /**
     * Each shared_paths entry may be a bare relative path (legacy — treated as a
     * directory) or a {path, type} pair where type is 'file' or 'dir'. The model
     * normalizes both shapes to {path, type} before persisting.
     */
    private function sharedPathRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (is_string($value)) {
                if (trim($value) === '' || strlen($value) > 255) {
                    $fail('Each shared path must be a non-empty string of at most 255 characters.');
                }

                return;
            }

            if (is_array($value)) {
                $path = $value['path'] ?? null;
                if (! is_string($path) || trim($path) === '' || strlen($path) > 255) {
                    $fail('Each shared path requires a non-empty "path" of at most 255 characters.');
                }
                if (! in_array($value['type'] ?? 'dir', ['file', 'dir'], true)) {
                    $fail('Shared path "type" must be "file" or "dir".');
                }

                return;
            }

            $fail('Each shared path must be a string or an object with "path" and "type".');
        };
    }

    /**
     * `hooks` is an object keyed by deploy stage → ordered list of command strings
     * (docs/SPEC.md §9, §16). Only the stages the engine actually runs are allowed
     * ({@see Project::HOOK_STAGES}: pre_activate, post_activate); each command is a raw
     * shell string run verbatim in the release dir (e.g. `php artisan migrate --force`,
     * `/usr/bin/ea-php83 artisan migrate`, `composer install --no-dev`, `npm run build`).
     * The model normalizes (trims, drops blanks) before persisting — this rule guards shape.
     */
    private function hooksRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                $fail('hooks must be an object keyed by deploy stage.');

                return;
            }

            foreach ($value as $stage => $commands) {
                if (! in_array($stage, Project::HOOK_STAGES, true)) {
                    $fail('Unknown hook stage "'.$stage.'". Allowed: '.implode(', ', Project::HOOK_STAGES).'.');

                    continue;
                }
                if (! is_array($commands)) {
                    $fail("hooks.{$stage} must be a list of command strings.");

                    continue;
                }
                foreach ($commands as $cmd) {
                    // null / blank entries are tolerated (UI may send empty rows; the model drops
                    // them on normalize). Only genuinely wrong types or over-long strings fail.
                    if ($cmd !== null && ! is_string($cmd)) {
                        $fail("Each command in hooks.{$stage} must be a string.");

                        break;
                    }
                    if (is_string($cmd) && strlen($cmd) > 1000) {
                        $fail("Each command in hooks.{$stage} must be at most 1000 characters.");

                        break;
                    }
                }
            }
        };
    }

    private function uniqueSlug(string $slug): string
    {
        $base = $slug;
        $n = 1;
        while (Project::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
