<?php

use App\Domain\Storage\PathJail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

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
