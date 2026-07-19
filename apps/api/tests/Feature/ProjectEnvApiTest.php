<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Deploy\EnvFileRenderer;
use App\Domain\Storage\PathJail;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_env_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->admin = User::factory()->create();
    $this->project = Project::factory()->create(['slug' => 'demo', 'base_path' => $this->base]);
});

afterEach(fn () => File::deleteDirectory($this->base));

it('stores env vars encrypted at rest and reads them back', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/projects/demo/env', [
        'env_vars' => [
            ['key' => 'APP_ENV', 'value' => 'production'],
            ['key' => 'API_SECRET', 'value' => 'supersecret-value'],
        ],
    ])->assertOk()
        ->assertJsonPath('data.vars.1.key', 'API_SECRET')
        ->assertJsonPath('data.vars.1.value', 'supersecret-value');

    // Ciphertext at rest: the raw column must not contain the plaintext secret.
    $raw = DB::table('projects')->where('id', $this->project->id)->value('env_vars');
    expect($raw)->not->toContain('supersecret-value')
        ->and(Crypt::decryptString($raw))->toContain('supersecret-value');

    $this->actingAs($this->admin)->getJson('/api/v1/projects/demo/env')
        ->assertOk()
        ->assertJsonPath('data.vars.1.value', 'supersecret-value');
});

it('forbids non-admins from reading or writing env vars', function () {
    $viewer = User::factory()->viewer()->create();

    $this->actingAs($viewer)->getJson('/api/v1/projects/demo/env')->assertForbidden();
    $this->actingAs($viewer)->putJson('/api/v1/projects/demo/env', ['env_vars' => []])->assertForbidden();
});

it('never exposes env vars through the project show or index payloads', function () {
    $this->project->env_vars = [['key' => 'LEAK', 'value' => 'nope']];
    $this->project->save();

    $this->actingAs($this->admin)->getJson('/api/v1/projects/demo')
        ->assertOk()
        ->assertJsonMissingPath('data.env_vars');

    $this->actingAs($this->admin)->getJson('/api/v1/projects')
        ->assertOk()
        ->assertJsonMissing(['env_vars' => [['key' => 'LEAK', 'value' => 'nope']]]);
});

it('rejects an invalid key, a duplicate key, and an oversized value', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/projects/demo/env', [
        'env_vars' => [['key' => '1BAD', 'value' => 'x']],
    ])->assertStatus(422)->assertJsonValidationErrors('env_vars.0.key');

    $this->actingAs($this->admin)->putJson('/api/v1/projects/demo/env', [
        'env_vars' => [['key' => 'DUP', 'value' => 'a'], ['key' => 'DUP', 'value' => 'b']],
    ])->assertStatus(422)->assertJsonValidationErrors('env_vars.1.key');

    $this->actingAs($this->admin)->putJson('/api/v1/projects/demo/env', [
        'env_vars' => [['key' => 'BIG', 'value' => str_repeat('a', 32769)]],
    ])->assertStatus(422)->assertJsonValidationErrors('env_vars.0.value');
});

it('audits env updates with key names but not values', function () {
    $this->actingAs($this->admin)->putJson('/api/v1/projects/demo/env', [
        'env_vars' => [['key' => 'SECRET_TOKEN', 'value' => 'do-not-log-me']],
    ])->assertOk();

    $log = AuditLog::query()->where('action', 'project.env_updated')->latest('id')->first();

    expect($log)->not->toBeNull()
        ->and($log->meta['keys'])->toContain('SECRET_TOKEN')
        ->and($log->meta['count'])->toBe(1)
        ->and(json_encode($log->meta))->not->toContain('do-not-log-me');
});

it('takes over an unmanaged shared/.env via adopt', function () {
    File::makeDirectory($this->base.'/shared', 0777, true, true);
    File::put($this->base.'/shared/.env', "APP_ENV=hand-made\n");

    $this->project->env_vars = [['key' => 'APP_ENV', 'value' => 'from-cporter']];
    $this->project->save();

    // Before adopt: the file exists but is not cPorter-managed.
    $this->actingAs($this->admin)->getJson('/api/v1/projects/demo/env')
        ->assertOk()
        ->assertJsonPath('data.file.exists', true)
        ->assertJsonPath('data.file.managed', false);

    $this->actingAs($this->admin)->postJson('/api/v1/projects/demo/env/adopt')
        ->assertOk()
        ->assertJsonPath('data.file.managed', true)
        ->assertJsonPath('message', fn ($m) => str_contains((string) $m, 'deploy'));

    expect(File::get($this->base.'/shared/.env'))
        ->toContain(EnvFileRenderer::MARKER)
        ->toContain('APP_ENV="from-cporter"');

    expect(AuditLog::query()->where('action', 'project.env_adopted')->exists())->toBeTrue();
});

it('carries env vars over when a project is cloned', function () {
    $this->project->env_vars = [['key' => 'SHARED_KEY', 'value' => 'shared-val']];
    $this->project->save();

    $this->actingAs($this->admin)->postJson('/api/v1/projects/demo/clone', [
        'name' => 'Demo Copy',
        'base_path' => $this->base.'/copy',
    ])->assertCreated();

    $clone = Project::query()->where('name', 'Demo Copy')->firstOrFail();

    $this->actingAs($this->admin)->getJson("/api/v1/projects/{$clone->slug}/env")
        ->assertOk()
        ->assertJsonPath('data.vars.0.key', 'SHARED_KEY')
        ->assertJsonPath('data.vars.0.value', 'shared-val');
});
