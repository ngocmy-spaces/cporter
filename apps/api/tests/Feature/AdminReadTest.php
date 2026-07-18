<?php

use App\Domain\Storage\PathJail;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_admin_'.uniqid();
    File::makeDirectory($this->base.'/releases', 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(\App\Adapters\Storage\StorageAdapter::class);

    $this->project = Project::factory()->create(['slug' => 'demo', 'base_path' => $this->base]);
    $this->admin = User::factory()->create();

    $this->makeRelease = function (string $id, ReleaseState $state) {
        $path = $this->base.'/releases/'.$id;
        File::makeDirectory($path, 0777, true, true);
        File::put($path.'/index.html', $id);

        return Release::create([
            'project_id' => $this->project->id,
            'path' => $path,
            'version' => $id,
            'state' => $state,
        ]);
    };
});

afterEach(fn () => File::deleteDirectory($this->base));

it('lists releases and deployments for a project', function () {
    $release = ($this->makeRelease)('r1', ReleaseState::Active);
    Deployment::create([
        'project_id' => $this->project->id,
        'release_id' => $release->id,
        'status' => DeploymentStatus::Success,
    ]);

    $this->actingAs($this->admin)->getJson('/api/v1/projects/demo/releases')
        ->assertOk()->assertJsonPath('data.0.version', 'r1');

    $this->actingAs($this->admin)->getJson('/api/v1/projects/demo/deployments')
        ->assertOk()->assertJsonPath('data.0.status', 'success');
});

it('shows recent deployments and a single deployment', function () {
    $release = ($this->makeRelease)('r1', ReleaseState::Active);
    $dep = Deployment::create([
        'project_id' => $this->project->id,
        'release_id' => $release->id,
        'status' => DeploymentStatus::Success,
    ]);

    $this->actingAs($this->admin)->getJson('/api/v1/deployments')
        ->assertOk()->assertJsonPath('data.0.id', $dep->id);

    $this->actingAs($this->admin)->getJson("/api/v1/deployments/{$dep->id}")
        ->assertOk()->assertJsonPath('data.id', $dep->id)
        ->assertJsonPath('data.project.slug', 'demo');
});

it('activates (rolls back to) a release from the admin UI', function () {
    // r1 currently active, r2 superseded → activate r2 makes it current.
    $r1 = ($this->makeRelease)('r1', ReleaseState::Active);
    $r2 = ($this->makeRelease)('r2', ReleaseState::Superseded);
    // point current at r1 first
    app(\App\Adapters\Storage\StorageAdapter::class)->activate($r1->path, $this->base.'/current');

    $this->actingAs($this->admin)->postJson("/api/v1/releases/{$r2->id}/activate")
        ->assertOk()->assertJsonPath('data.status', 'success');

    expect(file_get_contents($this->base.'/current/index.html'))->toBe('r2')
        ->and(Release::find($r2->id)->state->value)->toBe('active')
        ->and(Release::find($r1->id)->state->value)->toBe('superseded');
});

it('requires admin auth for read endpoints', function () {
    $this->getJson('/api/v1/deployments')->assertUnauthorized();
    $this->getJson('/api/v1/projects/demo/releases')->assertUnauthorized();
});
