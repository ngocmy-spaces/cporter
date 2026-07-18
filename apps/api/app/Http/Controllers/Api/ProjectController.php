<?php

namespace App\Http\Controllers\Api;

use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Http\Controllers\Controller;
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
            'shared_paths.*' => ['string', 'max:255'],
            'hooks' => ['nullable', 'array'],
        ]);

        if (! $jail->isInside($data['base_path'])) {
            throw ValidationException::withMessages([
                'base_path' => 'base_path must be within an allowed base path (CPORTER_ALLOWED_BASE_PATHS).',
            ]);
        }

        $data['slug'] = $this->uniqueSlug($data['slug'] ?? Str::slug($data['name']));

        $project = Project::create($data);

        app(\App\Domain\Audit\AuditLogger::class)->record('project.created', $project, ['slug' => $project->slug]);

        return response()->json(['data' => $project], 201);
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
