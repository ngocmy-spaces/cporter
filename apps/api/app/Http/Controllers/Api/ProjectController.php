<?php

namespace App\Http\Controllers\Api;

use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Http\Controllers\Controller;
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
    public function index(): JsonResponse
    {
        return response()->json(['data' => Project::query()->latest()->get()]);
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

        app(\App\Domain\Audit\AuditLogger::class)->record('project.created', $project, ['slug' => $project->slug]);

        return response()->json(['data' => $project], 201);
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
