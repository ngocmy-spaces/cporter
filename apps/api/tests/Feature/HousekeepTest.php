<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use App\Domain\System\ArtifactStorageHeartbeat;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_hk_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);
    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->project = Project::factory()->create(['slug' => 'demo', 'base_path' => $this->base]);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('fails timed-out deployments and releases their lock, sparing fresh ones', function () {
    File::put($this->base.'/deploy.lock', 'held');

    $release = Release::create([
        'project_id' => $this->project->id,
        'path' => $this->base.'/releases/r1',
        'state' => ReleaseState::Active,
    ]);
    $stuck = Deployment::create([
        'project_id' => $this->project->id,
        'release_id' => $release->id,
        'status' => DeploymentStatus::HooksPending,
        'started_at' => now()->subHour(),
    ]);
    $fresh = Deployment::create([
        'project_id' => $this->project->id,
        'status' => DeploymentStatus::Running,
        'started_at' => now(),
    ]);

    Artisan::call('cporter:housekeep');

    expect(Deployment::find($stuck->id)->status->value)->toBe('failed')
        ->and(Deployment::find($fresh->id)->status->value)->toBe('running')
        ->and(Release::find($release->id)->state->value)->toBe('failed')
        ->and(file_exists($this->base.'/deploy.lock'))->toBeFalse();
});

it('reclaims redundant artifact zips project-wide, sparing live and in-flight ones', function () {
    $dir = storage_path('app/artifacts/'.$this->project->slug);
    File::makeDirectory($dir, 0777, true, true);

    $mk = function (string $name) use ($dir): Artifact {
        $path = $dir.'/'.$name.'.zip';
        File::put($path, 'zip-'.$name);

        return Artifact::create([
            'project_id' => $this->project->id,
            'filename' => $name.'.zip',
            'size' => strlen('zip-'.$name),
            'sha256' => hash('sha256', $name),
            'storage_path' => $path,
            'status' => 'verified',
            'uploaded_at' => now(),
        ]);
    };

    $liveArt = $mk('live');
    $oldArt = $mk('old');
    $inflightArt = $mk('inflight');

    // Live release (protected).
    Release::create(['project_id' => $this->project->id, 'artifact_id' => $liveArt->id, 'path' => $this->base.'/releases/live', 'state' => ReleaseState::Active]);
    // Superseded release (its zip is dead weight → reclaimed).
    Release::create(['project_id' => $this->project->id, 'artifact_id' => $oldArt->id, 'path' => $this->base.'/releases/old', 'state' => ReleaseState::Superseded]);
    // In-flight deploy still needs its zip (protected).
    $inflightRelease = Release::create(['project_id' => $this->project->id, 'artifact_id' => $inflightArt->id, 'path' => $this->base.'/releases/inflight', 'state' => ReleaseState::Pending]);
    Deployment::create(['project_id' => $this->project->id, 'release_id' => $inflightRelease->id, 'status' => DeploymentStatus::Queued, 'started_at' => now()]);

    Artisan::call('cporter:housekeep');

    // Superseded zip gone from disk, row kept with size + pruned_at.
    expect(file_exists($oldArt->storage_path))->toBeFalse();
    $old = $oldArt->fresh();
    expect($old->storage_path)->toBeNull()
        ->and($old->pruned_at)->not->toBeNull()
        ->and($old->size)->toBeGreaterThan(0);

    // Live + in-flight zips untouched.
    expect($liveArt->fresh()->storage_path)->not->toBeNull()
        ->and(file_exists($liveArt->storage_path))->toBeTrue()
        ->and($inflightArt->fresh()->storage_path)->not->toBeNull()
        ->and(file_exists($inflightArt->storage_path))->toBeTrue();

    // A cleanup heartbeat was recorded for the System status.
    $beat = Setting::read(ArtifactStorageHeartbeat::KEY);
    expect($beat)->toBeArray()
        ->and($beat['reclaimed_count'])->toBe(1)
        ->and($beat['unpruned_count'])->toBe(2) // live + in-flight still on disk
        ->and($beat['store_bytes'])->toBeGreaterThan(0)
        ->and($beat)->toHaveKey('at');

    File::deleteDirectory($dir);
});

it('reclaims orphan zips + stale upload sessions the DB-driven prune cannot see', function () {
    $dir = storage_path('app/artifacts/'.$this->project->slug);
    File::makeDirectory($dir, 0777, true, true);
    $old = time() - 3600; // older than the deploy-timeout guard (default 1800s)

    // Referenced by a live Artifact row → kept regardless of age.
    $keptPath = $dir.'/kept.zip';
    File::put($keptPath, 'kept');
    touch($keptPath, $old);
    Artifact::create([
        'project_id' => $this->project->id, 'filename' => 'kept.zip', 'size' => 4,
        'sha256' => hash('sha256', 'kept'), 'storage_path' => $keptPath,
        'status' => 'verified', 'uploaded_at' => now(),
    ]);

    // Orphan (no row), old → reclaimed.
    $orphanOld = $dir.'/orphan-old.zip';
    File::put($orphanOld, 'orphan');
    touch($orphanOld, $old);

    // Orphan (no row) but FRESH → spared (could be a file mid-upload before its row exists).
    $orphanFresh = $dir.'/orphan-fresh.zip';
    File::put($orphanFresh, 'fresh');

    // Stale chunked-upload session → reclaimed.
    $session = storage_path('app/artifacts/uploads/deadbeef');
    File::makeDirectory($session, 0777, true, true);
    File::put($session.'/0', 'chunk');
    touch($session, $old);

    Artisan::call('cporter:housekeep');

    expect(file_exists($keptPath))->toBeTrue()          // referenced
        ->and(file_exists($orphanFresh))->toBeTrue()    // too fresh
        ->and(file_exists($orphanOld))->toBeFalse()     // orphan reclaimed
        ->and(is_dir($session))->toBeFalse();           // stale upload session reclaimed

    File::deleteDirectory($dir);
    File::deleteDirectory(storage_path('app/artifacts/uploads'));
});

it('reports artifact-store status via /system/storage', function () {
    $admin = User::factory()->create();

    // No sweep yet → unknown, but still reports whether pruning is enabled.
    $this->actingAs($admin)->getJson('/api/v1/system/storage')
        ->assertOk()
        ->assertJsonPath('data.state', 'unknown')
        ->assertJsonPath('data.prune_enabled', true);

    // A sweep with pruning disabled → warning with a pruning_disabled reason.
    config(['cporter.artifact.prune_after_deploy' => false]);
    Artisan::call('cporter:housekeep');

    $this->actingAs($admin)->getJson('/api/v1/system/storage')
        ->assertOk()
        ->assertJsonPath('data.state', 'warning')
        ->assertJsonPath('data.prune_enabled', false)
        ->assertJsonFragment(['warnings' => ['pruning_disabled']]);
});
