<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Audit\AuditLogger;
use App\Domain\Storage\PathJail;
use App\Jobs\PurgeProjectJob;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_padmin_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);

    $this->admin = User::factory()->create();
});

afterEach(fn () => File::deleteDirectory($this->base));

it('creates a project whose base_path is inside the jail', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Learn Platform',
        'base_path' => $this->base,
        'type' => 'laravel',
        'docroot_subpath' => 'public',
        'shared_paths' => ['.env', 'storage'],
    ])
        ->assertCreated()
        ->assertJsonPath('data.slug', 'learn-platform')
        ->assertJsonPath('data.type', 'laravel');
});

it('accepts typed {path,type} shared_paths and normalizes legacy strings', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Typed Site',
        'base_path' => $this->base,
        'type' => 'laravel',
        'docroot_subpath' => 'public',
        'shared_paths' => [
            '.env',                                    // legacy string → dir
            ['path' => 'storage', 'type' => 'dir'],
            ['path' => 'public/.env', 'type' => 'file'],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('data.shared_paths', [
            ['path' => '.env', 'type' => 'dir'],
            ['path' => 'storage', 'type' => 'dir'],
            ['path' => 'public/.env', 'type' => 'file'],
        ]);
});

it('rejects a shared path with an invalid type', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Bad Type',
        'base_path' => $this->base,
        'type' => 'static',
        'shared_paths' => [['path' => 'storage', 'type' => 'symlink']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shared_paths.0');
});

it('rejects a shared path object missing its path', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'No Path',
        'base_path' => $this->base,
        'type' => 'static',
        'shared_paths' => [['type' => 'file']],
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('shared_paths.0');
});

it('rejects a base_path outside the jail', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Evil',
        'base_path' => '/etc',
        'type' => 'static',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('base_path');
});

it('lists and shows projects', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->actingAs($this->admin)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'shop');

    $this->actingAs($this->admin)->getJson('/api/v1/projects/shop')
        ->assertOk()
        ->assertJsonPath('data.slug', 'shop');
});

it('requires admin auth to manage projects', function () {
    $this->postJson('/api/v1/projects', [
        'name' => 'x', 'base_path' => $this->base, 'type' => 'static',
    ])->assertUnauthorized();
});

it('updates editable project fields', function () {
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base, 'keep_releases' => 5]);

    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', [
        'name' => 'Shop Renamed',
        'keep_releases' => 12,
        'health_check_url' => 'https://shop.example.com/health',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Shop Renamed')
        ->assertJsonPath('data.keep_releases', 12)
        ->assertJsonPath('data.health_check_url', 'https://shop.example.com/health');
});

it('toggles a project between active and disabled', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', ['status' => 'disabled'])
        ->assertOk()
        ->assertJsonPath('data.status', 'disabled');

    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('data.status', 'active');
});

it('freezes slug, base_path and type once the project has releases', function () {
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base, 'type' => 'static']);
    $project->releases()->create(['version' => '1.0.0', 'path' => $this->base.'/releases/1', 'state' => 'active']);

    // Frozen identity field is rejected…
    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', ['type' => 'laravel'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('type');

    // …but ordinary config still updates.
    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', ['keep_releases' => 9])
        ->assertOk()
        ->assertJsonPath('data.keep_releases', 9);
});

it('allows changing type before any release exists', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base, 'type' => 'static']);

    $this->actingAs($this->admin)->patchJson('/api/v1/projects/shop', ['type' => 'laravel'])
        ->assertOk()
        ->assertJsonPath('data.type', 'laravel');
});

it('requires admin auth to update a project', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->patchJson('/api/v1/projects/shop', ['name' => 'x'])->assertUnauthorized();
});

it('soft-deletes a project without touching disk when purge is none', function () {
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);
    File::put($this->base.'/keep.txt', 'x');

    $this->actingAs($this->admin)->deleteJson('/api/v1/projects/shop', ['purge' => 'none'])
        ->assertOk();

    expect(Project::withTrashed()->find($project->id)->trashed())->toBeTrue();
    $this->actingAs($this->admin)->getJson('/api/v1/projects/shop')->assertNotFound();
    expect(File::exists($this->base.'/keep.txt'))->toBeTrue();
});

it('queues an async disk purge and marks the project deleting', function () {
    Queue::fake();
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->actingAs($this->admin)->deleteJson('/api/v1/projects/shop', ['purge' => 'all'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'deleting');

    expect($project->fresh()->trashed())->toBeFalse();
    Queue::assertPushed(
        PurgeProjectJob::class,
        fn ($job) => $job->level === 'all' && $job->project->id === $project->id,
    );
});

it('purges releases but keeps shared data, then hides the project', function () {
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base, 'status' => 'deleting']);
    File::makeDirectory($this->base.'/releases/1', 0777, true, true);
    File::put($this->base.'/releases/1/index.html', 'hi');
    File::makeDirectory($this->base.'/shared', 0777, true, true);
    File::put($this->base.'/shared/.env', 'SECRET=1');

    (new PurgeProjectJob($project, 'releases'))->handle(app(StorageAdapter::class), app(AuditLogger::class));

    expect(File::isDirectory($this->base.'/releases'))->toBeFalse();
    expect(File::exists($this->base.'/shared/.env'))->toBeTrue();
    expect(Project::withTrashed()->find($project->id)->trashed())->toBeTrue();
});

it('purges the entire base_path when the job runs with purge all', function () {
    $project = Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base, 'status' => 'deleting']);
    File::makeDirectory($this->base.'/releases/1', 0777, true, true);
    File::makeDirectory($this->base.'/shared', 0777, true, true);
    File::put($this->base.'/shared/.env', 'SECRET=1');

    (new PurgeProjectJob($project, 'all'))->handle(app(StorageAdapter::class), app(AuditLogger::class));

    expect(File::isDirectory($this->base))->toBeFalse();
    expect(Project::withTrashed()->find($project->id)->trashed())->toBeTrue();
});

it('rejects an unknown purge level', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->actingAs($this->admin)->deleteJson('/api/v1/projects/shop', ['purge' => 'wipe-everything'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('purge');
});

it('requires admin auth to delete a project', function () {
    Project::factory()->create(['slug' => 'shop', 'base_path' => $this->base]);

    $this->deleteJson('/api/v1/projects/shop')->assertUnauthorized();
});

it('paginates projects when a page param is present', function () {
    Project::factory()->count(3)->create();

    $this->actingAs($this->admin)->getJson('/api/v1/projects?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.last_page', 2);
});

it('returns the full unpaginated list when no page param is given', function () {
    Project::factory()->count(3)->create();

    $this->actingAs($this->admin)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');
});

it('filters projects by search term', function () {
    Project::factory()->create(['name' => 'Alpha', 'slug' => 'alpha', 'base_path' => $this->base]);
    Project::factory()->create(['name' => 'Beta', 'slug' => 'beta', 'base_path' => $this->base]);

    $this->actingAs($this->admin)->getJson('/api/v1/projects?search=alph')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'alpha');
});
