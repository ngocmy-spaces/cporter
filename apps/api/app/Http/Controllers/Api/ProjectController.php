<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectStatus;
use App\Enums\ProjectType;
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
        return response()->json(['data' => $project]);
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

    public function store(Request $request, PathJail $jail): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'alpha_dash', 'max:255', 'unique:projects,slug'],
            'base_path' => ['required', 'string', 'max:1024'],
            'type' => ['required', Rule::enum(ProjectType::class)],
            'docroot_subpath' => ['nullable', 'string', 'max:255'],
            'php_binary' => ['nullable', 'string', 'max:255'],
            'keep_releases' => ['nullable', 'integer', 'min:1', 'max:50'],
            'health_check_url' => ['nullable', 'url'],
            'shared_paths' => ['array'],
            'shared_paths.*' => [$this->sharedPathRule()],
            'hooks' => ['nullable', 'array'],
        ]);

        if (! $jail->isInside($data['base_path'])) {
            throw ValidationException::withMessages([
                'base_path' => 'base_path must be within an allowed base path (CPORTER_ALLOWED_BASE_PATHS).',
            ]);
        }

        // Ensure the (jail-validated) base directory exists so the first deploy can lock/extract.
        if (! is_dir($data['base_path'])) {
            @mkdir($data['base_path'], 0775, true);
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']));

        $project = Project::create($data);

        app(AuditLogger::class)->record('project.created', $project, ['slug' => $project->slug]);

        return response()->json(['data' => $project], 201);
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
            'php_binary' => ['sometimes', 'nullable', 'string', 'max:255'],
            'keep_releases' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:50'],
            'health_check_url' => ['sometimes', 'nullable', 'url'],
            'shared_paths' => ['sometimes', 'array'],
            'shared_paths.*' => [$this->sharedPathRule()],
            'hooks' => ['sometimes', 'nullable', 'array'],
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
