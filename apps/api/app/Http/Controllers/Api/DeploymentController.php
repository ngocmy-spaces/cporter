<?php

namespace App\Http\Controllers\Api;

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Audit\AuditLogger;
use App\Domain\Deploy\ArtifactUploadService;
use App\Domain\Deploy\DeployDispatcher;
use App\Enums\ArtifactStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectStatus;
use App\Enums\ReleaseState;
use App\Http\Controllers\Controller;
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
    public function __construct(
        private readonly StorageAdapter $storage,
        private readonly ArtifactUploadService $uploads,
    ) {}

    /** Single-request upload + deploy (artifacts ≤ post_max_size). */
    public function store(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }
        if ($disabled = $this->guardProjectEnabled($project)) {
            return $disabled;
        }
        if ($replay = $this->idempotentReplay($request, $project)) {
            return $replay;
        }

        $maxKb = (int) (config('cporter.artifact.max_bytes', 256 * 1024 * 1024) / 1024);
        $data = $request->validate([
            'artifact' => ['required', 'file', 'max:'.$maxKb],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'version' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $storagePath = $this->storage->putArtifact($request->file('artifact')->getRealPath(), $project->slug);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return $this->createDeployment($request, $project, $storagePath, $data['sha256'], $data['version'] ?? null);
    }

    // ── Chunked upload (docs/SPEC.md §6): init → chunks → complete ────────────────

    public function uploadInit(Request $request, Project $project): JsonResponse
    {
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }
        if ($disabled = $this->guardProjectEnabled($project)) {
            return $disabled;
        }

        return response()->json(['data' => ['upload_id' => $this->uploads->init()]], 201);
    }

    public function uploadChunk(Request $request, Project $project, string $uploadId, int $index): JsonResponse
    {
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }

        try {
            // Chunk body is sent raw (application/octet-stream) to bypass post_max_size limits.
            $this->uploads->putChunk($uploadId, $index, $request->getContent());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['received' => $index]]);
    }

    public function uploadComplete(Request $request, Project $project, string $uploadId): JsonResponse
    {
        if ($denied = $this->guardProjectScope($request, $project)) {
            return $denied;
        }
        if ($disabled = $this->guardProjectEnabled($project)) {
            return $disabled;
        }
        if ($replay = $this->idempotentReplay($request, $project)) {
            return $replay;
        }

        $data = $request->validate([
            'sha256' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'version' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $assembled = $this->uploads->assemble($uploadId);
            $storagePath = $this->storage->putArtifact($assembled, $project->slug);
        } catch (\Throwable $e) {
            $this->uploads->cleanup($uploadId);

            return response()->json(['error' => $e->getMessage()], 422);
        }

        $this->uploads->cleanup($uploadId);

        return $this->createDeployment($request, $project, $storagePath, $data['sha256'], $data['version'] ?? null);
    }

    /** Verify integrity, create artifact/release/deployment records, dispatch the pipeline. */
    private function createDeployment(Request $request, Project $project, string $storagePath, string $expectedSha, ?string $version): JsonResponse
    {
        $actualSha = hash_file('sha256', $storagePath);
        if ($actualSha === false || ! hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
            @unlink($storagePath);

            return response()->json([
                'error' => 'Artifact hash mismatch.',
                'expected' => $expectedSha,
                'actual' => $actualSha ?: null,
            ], 422);
        }

        $artifact = Artifact::create([
            'project_id' => $project->id,
            'filename' => basename($storagePath),
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
            'version' => $version ?? $releaseId,
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
            'idempotency_key' => $request->header('Idempotency-Key'),
        ]);

        // Kick the per-project FIFO dispatcher: it starts this deploy immediately if the project is
        // idle, otherwise the row stays `queued` as backlog and is drained when the project frees
        // (docs/SPEC.md §6, §10). Under the sync queue driver (tests) the claimed deploy runs inline.
        app(DeployDispatcher::class)->claimNextFor($project);

        app(AuditLogger::class)->record(
            'deployment.created',
            $deployment,
            ['project' => $project->slug, 'version' => $release->version],
            $apiKey?->name,
        );

        return response()->json(['data' => $deployment->fresh()->load('release')], 202);
    }

    private function idempotentReplay(Request $request, Project $project): ?JsonResponse
    {
        $key = $request->header('Idempotency-Key');
        if (! $key) {
            return null;
        }

        $existing = Deployment::query()
            ->where('project_id', $project->id)
            ->where('idempotency_key', $key)
            ->first();

        return $existing ? response()->json(['data' => $existing->load('release')], 200) : null;
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

    // ── Admin (session) read endpoints ───────────────────────────────────────────

    /** Recent deployments across all projects (dashboard / Deployments page). */
    public function recent(): JsonResponse
    {
        return response()->json([
            'data' => Deployment::query()->with(['project', 'release'])->latest()->limit(50)->get(),
        ]);
    }

    /** Deployments for one project. */
    public function index(Project $project): JsonResponse
    {
        return response()->json([
            'data' => $project->deployments()->with('release')->latest()->limit(100)->get(),
        ]);
    }

    /** A single deployment (global path, admin) — used for step polling in the UI. */
    public function detail(Deployment $deployment): JsonResponse
    {
        return response()->json(['data' => $deployment->load(['project', 'release.artifact'])]);
    }

    private function guardProjectScope(Request $request, Project $project): ?JsonResponse
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey?->project_id !== null && $apiKey->project_id !== $project->id) {
            return response()->json(['error' => 'API key is not authorized for this project.'], 403);
        }

        return null;
    }

    /** A disabled or deleting project (docs/SPEC.md §5) rejects new deploys. */
    private function guardProjectEnabled(Project $project): ?JsonResponse
    {
        if ($project->status === ProjectStatus::Disabled) {
            return response()->json(['error' => 'Project is disabled; deploys are not accepted.'], 409);
        }
        if ($project->status === ProjectStatus::Deleting) {
            return response()->json(['error' => 'Project is being deleted; deploys are not accepted.'], 409);
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
