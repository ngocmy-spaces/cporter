<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Deployment;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/** @param array<string, string> $entries */
function buildZip(array $entries): string
{
    $path = sys_get_temp_dir().'/cporter_up_'.uniqid().'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

function upload(string $zipPath): UploadedFile
{
    return new UploadedFile($zipPath, 'artifact.zip', 'application/zip', null, true);
}

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_proj_'.uniqid();
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
        'health_check_url' => null, // no HTTP in these pure-deploy tests
    ]);

    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $this->project->id);
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/demo'));
});

it('rejects a deploy to a disabled project', function () {
    $this->project->update(['status' => 'disabled']);

    $zip = buildZip(['index.html' => '<h1>cPorter</h1>']);

    $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(409);

    expect(is_link($this->base.'/current'))->toBeFalse();
});

it('rejects a deploy to a project being deleted', function () {
    $this->project->update(['status' => 'deleting']);

    $zip = buildZip(['index.html' => '<h1>cPorter</h1>']);

    $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(409);
});

it('queues a concurrent deploy as FIFO backlog instead of rejecting it', function () {
    // A deploy is already actively running for this project (holds the lock).
    $inflight = Deployment::create([
        'project_id' => $this->project->id,
        'trigger' => 'api',
        'status' => 'running',
        'started_at' => now(),
    ]);

    $zip = buildZip(['index.html' => '<h1>cPorter</h1>']);
    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ]);

    // Accepted + parked as queued backlog — not 409, not run.
    $res->assertStatus(202)->assertJsonPath('data.status', 'queued');
    expect(is_link($this->base.'/current'))->toBeFalse()
        ->and($inflight->fresh()->status->value)->toBe('running'); // pre-existing run untouched
});

it('fails validation when the docroot is missing its entrypoint', function () {
    // A static artifact with no index.html — the docroot exists but has no entrypoint.
    $zip = buildZip(['readme.txt' => 'no entrypoint here']);

    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ]);

    $res->assertStatus(202)->assertJsonPath('data.status', 'failed');

    $validate = collect($res->json('data.steps'))->firstWhere('name', 'validate');
    expect($validate['status'])->toBe('failed')
        ->and($validate['error'])->toContain('entrypoint');

    // Nothing went live.
    expect(is_link($this->base.'/current'))->toBeFalse();
});

it('deploys a static artifact end to end', function () {
    $zip = buildZip(['index.html' => '<h1>cPorter</h1>']);

    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
        'version' => '1.0.0',
    ]);

    $res->assertStatus(202)->assertJsonPath('data.status', 'success');

    expect(is_link($this->base.'/current'))->toBeTrue()
        ->and(file_get_contents($this->base.'/current/index.html'))->toBe('<h1>cPorter</h1>');

    $steps = collect($res->json('data.steps'))->pluck('name')->all();
    expect($steps)->toContain('lock', 'extract', 'link_shared', 'validate', 'activate', 'prune');
});

it('rejects a hash mismatch with 422', function () {
    $zip = buildZip(['index.html' => 'x']);

    $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => str_repeat('0', 64),
    ])->assertStatus(422);
});

it('requires the deploy scope', function () {
    ['token' => $readOnly] = app(ApiKeyService::class)->generate('ro', ['read'], $this->project->id);
    $zip = buildZip(['index.html' => 'x']);

    $this->withToken($readOnly)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(403);
});

it('lets a read-scope API key list releases (T5.4)', function () {
    $zip = buildZip(['index.html' => 'hi']);
    $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(202);

    $this->withToken($this->token)->getJson('/api/v1/projects/demo/releases')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('forbids listing releases with an API key that lacks the read scope', function () {
    ['token' => $deployOnly] = app(ApiKeyService::class)->generate('deploy-only', ['deploy'], $this->project->id);

    $this->withToken($deployOnly)->getJson('/api/v1/projects/demo/releases')
        ->assertStatus(403);
});

it('forbids a project-scoped key from listing another project\'s releases', function () {
    Project::factory()->create(['slug' => 'other', 'base_path' => $this->base.'-other']);

    // $this->token is bound to the 'demo' project.
    $this->withToken($this->token)->getJson('/api/v1/projects/other/releases')
        ->assertStatus(403);
});

it('polls deployment status via GET', function () {
    $zip = buildZip(['index.html' => 'hi']);
    $created = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->json('data');

    $this->withToken($this->token)
        ->getJson("/api/v1/projects/demo/deployments/{$created['id']}")
        ->assertOk()
        ->assertJsonPath('data.status', 'success');
});

it('renders managed env vars into shared/.env and symlinks it into the release', function () {
    $this->project->update(['env_vars' => [['key' => 'APP_ENV', 'value' => 'production']]]);

    $zip = buildZip(['index.html' => 'hi']);
    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(202);

    $res->assertJsonPath('data.status', 'success');

    $steps = collect($res->json('data.steps'));
    expect($steps->firstWhere('name', 'write_env')['status'])->toBe('success')
        ->and(file_get_contents($this->base.'/shared/.env'))->toContain('APP_ENV="production"')
        ->and(is_link($this->base.'/current/.env'))->toBeTrue()
        ->and(file_get_contents($this->base.'/current/.env'))->toContain('APP_ENV="production"');
});

it('warns and skips writing when shared/.env is unmanaged, without failing the deploy', function () {
    File::makeDirectory($this->base.'/shared', 0777, true, true);
    File::put($this->base.'/shared/.env', "APP_ENV=hand-made\n");
    $this->project->update(['env_vars' => [['key' => 'APP_ENV', 'value' => 'from-cporter']]]);

    $zip = buildZip(['index.html' => 'hi']);
    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(202);

    $res->assertJsonPath('data.status', 'success'); // deploy still succeeds

    $writeEnv = collect($res->json('data.steps'))->firstWhere('name', 'write_env');
    expect($writeEnv['status'])->toBe('warning')
        ->and($writeEnv['note'])->toContain('not managed by cPorter')
        ->and(file_get_contents($this->base.'/shared/.env'))->toBe("APP_ENV=hand-made\n"); // untouched
});

it('does not touch shared/.env when no env vars are configured', function () {
    $zip = buildZip(['index.html' => 'hi']);
    $res = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(202);

    expect(collect($res->json('data.steps'))->firstWhere('name', 'write_env'))->toBeNull()
        ->and(file_exists($this->base.'/shared/.env'))->toBeFalse();
});

it('fails the deploy when the project is already locked', function () {
    File::put($this->base.'/deploy.lock', 'held-by-another'); // fresh lock held by someone else
    $zip = buildZip(['index.html' => 'x']);

    $created = $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
        'artifact' => upload($zip),
        'sha256' => hash_file('sha256', $zip),
    ])->assertStatus(202)->json('data');

    expect($created['status'])->toBe('failed')
        ->and(collect($created['steps'])->firstWhere('name', 'lock')['status'])->toBe('failed')
        ->and(file_exists($this->base.'/current'))->toBeFalse();
});
