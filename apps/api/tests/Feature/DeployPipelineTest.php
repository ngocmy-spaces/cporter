<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
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
