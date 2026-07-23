<?php

namespace App\Http\Controllers;

use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Return a `403` if the request's API key is bound to a different project, else null. A
     * project-scoped key may only read/act on its own project; admin-session requests carry no
     * `api_key` and always pass (docs/SPEC.md §12).
     */
    protected function guardApiKeyProjectScope(Request $request, Project $project): ?JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey?->project_id !== null && $apiKey->project_id !== $project->id) {
            return response()->json(['error' => 'API key is not authorized for this project.'], 403);
        }

        return null;
    }

    /**
     * Return a `409 Conflict` if a deploy is ACTIVELY running (or finalizing hooks) for the project,
     * else null. Guards manual rollback/activate: they share the project's `deploy.lock` with a
     * running deploy, so they can't run mid-pipeline. A purely-`queued` backlog does NOT block a
     * rollback — an operator's "get back to good" should not wait on deploys that haven't started
     * (docs/SPEC.md §6, §8, §20.2). Deploys themselves no longer use this guard — they queue (§10).
     */
    protected function guardNoConcurrentDeploy(Project $project): ?JsonResponse
    {
        $active = $project->activeDeployment();
        if ($active === null) {
            return null;
        }

        return response()->json([
            'error' => 'A deployment is currently running for this project; retry once it finishes.',
            'deployment_id' => $active->id,
            'status' => $active->status->value,
        ], 409);
    }
}
