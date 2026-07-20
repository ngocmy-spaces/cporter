<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Domain\Deploy\DeployException;
use App\Domain\Deploy\RollbackEngine;
use App\Enums\DeploymentTrigger;
use App\Enums\ReleaseState;
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

    /**
     * Releases that still exist on disk and can be re-activated (live + prior). Pruned/failed/
     * in-progress releases are omitted — they can't be rolled back to, so listing them only offers
     * dead "Activate" buttons. Full history (including these) lives in the Deployments view. The
     * result is naturally bounded by keep_releases (docs/SPEC.md §6, §8).
     */
    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'data' => $project->releases()
                ->with('artifact')
                ->whereIn('state', [ReleaseState::Active->value, ReleaseState::Superseded->value])
                ->latest()
                ->get(),
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

        app(AuditLogger::class)->record(
            'release.activated',
            $release,
            ['project' => $project->slug, 'version' => $release->version],
            $request->user()?->email,
        );

        return response()->json(['data' => $deployment->load('release')]);
    }
}
