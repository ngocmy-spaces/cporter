<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
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
