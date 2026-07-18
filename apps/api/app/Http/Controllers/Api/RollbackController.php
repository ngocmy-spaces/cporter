<?php

namespace App\Http\Controllers\Api;

use App\Domain\Deploy\DeployException;
use App\Domain\Deploy\RollbackEngine;
use App\Enums\DeploymentTrigger;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CI-facing rollback endpoint (docs/SPEC.md §7, §8). Auth = API key + scope:rollback.
 * Code-only: re-points `current` at the previous (or a specified) release.
 */
class RollbackController extends Controller
{
    public function __construct(private readonly RollbackEngine $rollback) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey?->project_id !== null && $apiKey->project_id !== $project->id) {
            return response()->json(['error' => 'API key is not authorized for this project.'], 403);
        }

        $data = $request->validate([
            'release_id' => ['nullable', 'integer'],
        ]);

        $target = null;
        if (! empty($data['release_id'])) {
            $target = Release::query()->where('project_id', $project->id)->find($data['release_id']);
            if ($target === null) {
                return response()->json(['error' => 'Release not found for this project.'], 404);
            }
        }

        try {
            $deployment = $this->rollback->rollback($project, $target, DeploymentTrigger::Api, $apiKey?->name);
        } catch (DeployException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        app(\App\Domain\Audit\AuditLogger::class)->record(
            'deployment.rolled_back',
            $deployment,
            ['project' => $project->slug, 'release_id' => $deployment->release_id],
            $apiKey?->name,
        );

        return response()->json(['data' => $deployment->load('release')]);
    }
}
