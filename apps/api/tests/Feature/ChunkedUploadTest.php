<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

// buildZip() is defined in DeployPipelineTest.php (global test helper).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_ck_'.uniqid();
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
        'health_check_url' => null,
    ]);
    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read'], $this->project->id);

    // call() bypasses withToken's default headers, so pass auth via the server array.
    $this->putChunk = fn (string $uploadId, int $i, string $bytes) => $this->call(
        'PUT',
        "/api/v1/projects/demo/artifacts/uploads/{$uploadId}/chunks/{$i}",
        [], [], [],
        ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token],
        $bytes,
    );
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/demo'));
    File::deleteDirectory(storage_path('app/artifacts/uploads'));
});

it('deploys via a chunked upload', function () {
    $bytes = file_get_contents(buildZip(['index.html' => '<h1>chunked</h1>']));
    $sha = hash('sha256', $bytes);
    $half = intdiv(strlen($bytes), 2);

    $uploadId = $this->withToken($this->token)
        ->postJson('/api/v1/projects/demo/artifacts/uploads')
        ->assertCreated()
        ->json('data.upload_id');

    ($this->putChunk)($uploadId, 0, substr($bytes, 0, $half))->assertOk();
    ($this->putChunk)($uploadId, 1, substr($bytes, $half))->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/projects/demo/artifacts/uploads/{$uploadId}/complete", ['sha256' => $sha, 'version' => '1.0.0'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'success');

    expect(file_get_contents($this->base.'/current/index.html'))->toBe('<h1>chunked</h1>');
});

it('rejects a chunked upload whose assembled hash mismatches', function () {
    $bytes = file_get_contents(buildZip(['index.html' => 'x']));

    $uploadId = $this->withToken($this->token)
        ->postJson('/api/v1/projects/demo/artifacts/uploads')
        ->json('data.upload_id');

    ($this->putChunk)($uploadId, 0, $bytes)->assertOk();

    $this->withToken($this->token)
        ->postJson("/api/v1/projects/demo/artifacts/uploads/{$uploadId}/complete", ['sha256' => str_repeat('0', 64)])
        ->assertStatus(422);
});
