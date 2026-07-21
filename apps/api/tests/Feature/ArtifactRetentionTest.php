<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Deploy\ArtifactPruner;
use App\Domain\Deploy\RollbackEngine;
use App\Domain\Storage\PathJail;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectType;
use App\Enums\ReleaseState;
use App\Models\Artifact;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

// buildZip()/upload() are shared globals from DeployPipelineTest.

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_artret_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->project = Project::factory()->create([
        'slug' => 'demo',
        'base_path' => $this->base,
        'type' => ProjectType::StaticSite,
        'docroot_subpath' => null,
        'shared_paths' => [],
        'keep_releases' => 3,
        'health_check_url' => null,
    ]);

    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $this->project->id);
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/demo'));
});

/** Deploy a one-file static artifact and return the deployment payload. */
function deployStatic(object $ctx, string $marker): array
{
    $zip = buildZip(['index.html' => $marker]);

    return $ctx->withToken($ctx->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->json('data');
}

it('keeps only the live artifact zip and reclaims superseded ones, preserving the DB row', function () {
    $first = deployStatic($this, 'one');
    $firstArtifactId = Release::find($first['release_id'])->artifact_id;

    // After the first deploy the artifact is live — its zip is protected.
    expect(Artifact::find($firstArtifactId)->storage_path)->not->toBeNull();

    $second = deployStatic($this, 'two');

    // The now-superseded first artifact's zip is reclaimed, but the row survives for reporting.
    $a1 = Artifact::find($firstArtifactId);
    expect($a1->storage_path)->toBeNull()
        ->and($a1->pruned_at)->not->toBeNull()
        ->and($a1->size)->toBeGreaterThan(0)
        ->and($a1->sha256)->not->toBeNull();

    // The live (second) artifact's zip stays on disk.
    $a2 = Artifact::find(Release::find($second['release_id'])->artifact_id);
    expect($a2->storage_path)->not->toBeNull()
        ->and(is_file($a2->storage_path))->toBeTrue();

    // The cleanup is recorded as a pipeline step on the second deploy.
    expect(collect($second['steps'])->pluck('name')->all())->toContain('prune_artifacts');
});

it('records prune_artifacts but reclaims nothing on the very first deploy', function () {
    $first = deployStatic($this, 'solo');

    $step = collect($first['steps'])->firstWhere('name', 'prune_artifacts');
    expect($step)->not->toBeNull()
        ->and($step['status'])->toBe('success')
        ->and(Artifact::find(Release::find($first['release_id'])->artifact_id)->storage_path)->not->toBeNull();
});

it('retains every zip when pruning is disabled', function () {
    config(['cporter.artifact.prune_after_deploy' => false]);

    $first = deployStatic($this, 'one');
    $firstArtifactId = Release::find($first['release_id'])->artifact_id;
    deployStatic($this, 'two');

    expect(Artifact::find($firstArtifactId)->storage_path)->not->toBeNull();
});

it('keeps the deploy successful with a single warning when artifact cleanup fails', function () {
    // Simulate the cleanup blowing up (e.g. schema not migrated yet).
    app()->bind(ArtifactPruner::class, fn () => new class(app(StorageAdapter::class)) extends ArtifactPruner
    {
        public function prune(Project $project): array
        {
            throw new RuntimeException('boom');
        }
    });

    $dep = deployStatic($this, 'one');

    // Deploy still succeeds — post-deploy cleanup is non-fatal.
    expect($dep['status'])->toBe('success');

    // Exactly one prune_artifacts step, recorded as a warning (not a failed/red step, not doubled).
    $pruneSteps = collect($dep['steps'])->where('name', 'prune_artifacts')->values();
    expect($pruneSteps)->toHaveCount(1)
        ->and($pruneSteps[0]['status'])->toBe('warning')
        ->and($pruneSteps[0]['note'])->toContain('boom');
});

it('makes a new deploy live even while rolled back to an older release', function () {
    $a = deployStatic($this, 'aaa');
    deployStatic($this, 'bbb');

    // Roll back to the first release.
    app(RollbackEngine::class)->rollback(
        $this->project->fresh(),
        Release::find($a['release_id']),
        DeploymentTrigger::Manual,
        'tester',
    );
    expect(file_get_contents($this->base.'/current/index.html'))->toBe('aaa')
        ->and(Release::find($a['release_id'])->state)->toBe(ReleaseState::Active);

    // A brand-new deploy must go live regardless of the temporary rollback.
    $c = deployStatic($this, 'ccc');

    expect(file_get_contents($this->base.'/current/index.html'))->toBe('ccc')
        ->and(Release::find($c['release_id'])->state)->toBe(ReleaseState::Active)
        ->and(Release::find($a['release_id'])->fresh()->state)->toBe(ReleaseState::Superseded);
});
