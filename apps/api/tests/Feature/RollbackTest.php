<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Auth\ApiKeyService;
use App\Domain\Storage\PathJail;
use App\Enums\ProjectType;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

// buildZip() / upload() are defined in DeployPipelineTest.php (global test helpers).

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_rb_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);

    config(['cporter.allowed_base_paths' => [$this->base], 'cporter.health_check.timeout' => 0]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $this->project = Project::factory()->create([
        'slug' => 'demo',
        'base_path' => $this->base,
        'type' => ProjectType::StaticSite,
        'docroot_subpath' => null,
        'shared_paths' => [],
        'keep_releases' => 5,
        'health_check_url' => 'https://demo.test/up',
    ]);

    ['token' => $this->token] = app(ApiKeyService::class)->generate('ci', ['deploy', 'read', 'rollback'], $this->project->id);

    $this->deploy = function (string $body) {
        $zip = buildZip(['index.html' => $body]);

        return $this->withToken($this->token)->post('/api/v1/projects/demo/deployments', [
            'artifact' => upload($zip),
            'sha256' => hash_file('sha256', $zip),
        ]);
    };
});

afterEach(function () {
    File::deleteDirectory($this->base);
    File::deleteDirectory(storage_path('app/artifacts/demo'));
});

it('rolls back to the previous release', function () {
    Http::fake(['*' => Http::response('', 200)]);

    ($this->deploy)('A')->assertStatus(202)->assertJsonPath('data.status', 'success');
    ($this->deploy)('B')->assertStatus(202)->assertJsonPath('data.status', 'success');
    expect(file_get_contents($this->base.'/current/index.html'))->toBe('B');

    $this->withToken($this->token)->postJson('/api/v1/projects/demo/rollback')
        ->assertOk()
        ->assertJsonPath('data.status', 'success');

    expect(file_get_contents($this->base.'/current/index.html'))->toBe('A');
});

it('rolls back to a specific release id', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $r1 = ($this->deploy)('A')->json('data.release_id');
    ($this->deploy)('B');
    ($this->deploy)('C');
    expect(file_get_contents($this->base.'/current/index.html'))->toBe('C');

    $this->withToken($this->token)->postJson('/api/v1/projects/demo/rollback', ['release_id' => $r1])
        ->assertOk();

    expect(file_get_contents($this->base.'/current/index.html'))->toBe('A')
        ->and(Release::find($r1)->state->value)->toBe('active');
});

it('returns 422 when there is no previous release', function () {
    Http::fake(['*' => Http::response('', 200)]);

    ($this->deploy)('A'); // only one release — nothing superseded

    $this->withToken($this->token)->postJson('/api/v1/projects/demo/rollback')
        ->assertStatus(422);
});

it('requires the rollback scope', function () {
    Http::fake(['*' => Http::response('', 200)]);
    ($this->deploy)('A');
    ($this->deploy)('B');

    ['token' => $noRollback] = app(ApiKeyService::class)->generate('ci2', ['deploy', 'read'], $this->project->id);

    $this->withToken($noRollback)->postJson('/api/v1/projects/demo/rollback')
        ->assertStatus(403);
});

it('auto-rolls back when the health check fails after activation', function () {
    // First deploy healthy (200), second deploy unhealthy (500) → auto-rollback to first.
    Http::fakeSequence()->pushStatus(200)->pushStatus(500);

    ($this->deploy)('A')->assertJsonPath('data.status', 'success');

    $res = ($this->deploy)('B');
    $res->assertStatus(202)->assertJsonPath('data.status', 'rolled_back');

    // current is back on the first release; the failed release is marked failed.
    expect(file_get_contents($this->base.'/current/index.html'))->toBe('A')
        ->and(collect($res->json('data.steps'))->pluck('name')->all())->toContain('health_check', 'auto_rollback');
});
