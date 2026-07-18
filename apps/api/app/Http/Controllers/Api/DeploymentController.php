<?php

namespace App\Http\Controllers\Api;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\ArtifactStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ReleaseState;
use App\Http\Controllers\Controller;
use App\Jobs\DeployProjectJob;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CI-facing deploy endpoints (docs/SPEC.md §6, §7). Auth = API key (apikey middleware).
 */
class DeploymentController extends Controller
{
    public function __construct(private readonly StorageAdapter $storage) {}

    public function store(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }

        // Idempotency: replay returns the original deployment.
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey) {
            $existing = Deployment::query()
                ->where('project_id', $project->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return response()->json(['data' => $existing->load('release')], 200);
            }
        }

        $maxKb = (int) (config('cporter.artifact.max_bytes', 256 * 1024 * 1024) / 1024);
        $data = $request->validate([
            'artifact' => ['required', 'file', 'max:'.$maxKb],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'version' => ['nullable', 'string', 'max:255'],
        ]);

        // Persist the upload, then verify integrity before doing any heavy work.
        try {
            $storagePath = $this->storage->putArtifact($request->file('artifact')->getRealPath(), $project->slug);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $actualSha = hash_file('sha256', $storagePath);
        if ($actualSha === false || ! hash_equals(strtolower($data['sha256']), strtolower($actualSha))) {
            @unlink($storagePath);

            return response()->json([
                'error' => 'Artifact hash mismatch.',
                'expected' => $data['sha256'],
                'actual' => $actualSha ?: null,
            ], 422);
        }

        $artifact = Artifact::create([
            'project_id' => $project->id,
            'filename' => $request->file('artifact')->getClientOriginalName(),
            'size' => filesize($storagePath) ?: 0,
            'sha256' => $actualSha,
            'storage_path' => $storagePath,
            'status' => ArtifactStatus::Verified,
            'uploaded_at' => now(),
        ]);

        $releaseId = $this->nextReleaseId($project);
        $release = Release::create([
            'project_id' => $project->id,
            'artifact_id' => $artifact->id,
            'version' => $data['version'] ?? $releaseId,
            'path' => rtrim($project->base_path, '/').'/releases/'.$releaseId,
            'state' => ReleaseState::Pending,
        ]);

        $apiKey = $request->attributes->get('api_key');
        $deployment = Deployment::create([
            'project_id' => $project->id,
            'release_id' => $release->id,
            'trigger' => DeploymentTrigger::Api,
            'status' => DeploymentStatus::Queued,
            'actor' => $apiKey?->name,
            'idempotency_key' => $idempotencyKey,
        ]);

        DeployProjectJob::dispatch($deployment);

        return response()->json(['data' => $deployment->fresh()->load('release')], 202);
    }

    public function show(Request $request, Project $project, Deployment $deployment): JsonResponse
    {
        if ($deployment->project_id !== $project->id) {
            abort(404);
        }
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }

        return response()->json(['data' => $deployment->load('release')]);
    }

    private function guardProjectScope(Request $request, Project $project): ?JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey?->project_id !== null && $apiKey->project_id !== $project->id) {
            return response()->json(['error' => 'API key is not authorized for this project.'], 403);
        }

        return null;
    }

    private function nextReleaseId(Project $project): string
    {
        $seq = Release::query()
            ->where('project_id', $project->id)
            ->whereDate('created_at', now()->toDateString())
            ->count() + 1;

        return now()->format('Ymd').'_'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT);
    }
}
