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
 * Admin (session) endpoints for releases (docs/SPEC.md §8, §13).
 */
class ReleaseController extends Controller
{
    public function __construct(private readonly RollbackEngine $rollback) {}

    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'data' => $project->releases()->with('artifact')->latest()->get(),
        ]);
    }

    /** Activate (roll back / forward to) a specific release from the Admin UI. */
    public function activate(Request $request, Release $release): JsonResponse
    {
        $project = $release->project;

        try {
            $deployment = $this->rollback->rollback(
                $project,
                $release,
                DeploymentTrigger::Manual,
                $request->user()?->email,
            );
        } catch (DeployException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $deployment->load('release')]);
    }
}
