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
