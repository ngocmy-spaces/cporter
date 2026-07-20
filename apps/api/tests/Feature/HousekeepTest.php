<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Artifact;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
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

    File::deleteDirectory($dir);
});
