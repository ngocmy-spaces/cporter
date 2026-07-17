<?php

use App\Domain\Auth\ApiKeyService;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(ApiKeyService::class);
});

it('generates a token and authenticates it', function () {
    ['token' => $token, 'api_key' => $key] = $this->service->generate('ci', ['deploy']);

    expect($token)->toStartWith('cpk_');

    $resolved = $this->service->authenticate($token);
    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($key->id);
});

it('rejects malformed, revoked, and expired tokens', function () {
    ['token' => $token, 'api_key' => $key] = $this->service->generate('ci');

    expect($this->service->authenticate('nope'))->toBeNull()
        ->and($this->service->authenticate('cpk_deadbeef'))->toBeNull()
        ->and($this->service->authenticate(null))->toBeNull();

    $key->forceFill(['revoked_at' => now()])->save();
    expect($this->service->authenticate($token))->toBeNull();

    $key->forceFill(['revoked_at' => null, 'expires_at' => now()->subMinute()])->save();
    expect($this->service->authenticate($token))->toBeNull();
});

it('authenticates /whoami with a bearer token and 401s without', function () {
    $project = Project::factory()->create();
    ['token' => $token] = $this->service->generate('ci', ['deploy'], $project->id);

    // No token → 401 (assert before setting the bearer header, which persists on the client).
    $this->getJson('/api/v1/whoami')->assertUnauthorized();

    $this->withToken($token)->getJson('/api/v1/whoami')
        ->assertOk()
        ->assertJsonPath('data.name', 'ci')
        ->assertJsonPath('data.project_id', $project->id);
});

it('enforces the scope middleware', function () {
    Route::middleware(['apikey', 'scope:deploy'])
        ->get('/api/v1/_test/deploy', fn () => response()->json(['data' => 'ok']));

    ['token' => $readToken] = $this->service->generate('read-only', ['read']);
    ['token' => $deployToken] = $this->service->generate('deployer', ['deploy']);

    $this->withToken($readToken)->getJson('/api/v1/_test/deploy')->assertForbidden();
    $this->withToken($deployToken)->getJson('/api/v1/_test/deploy')->assertOk();
});

it('treats the admin scope as a super-scope', function () {
    ['token' => $token] = $this->service->generate('admin-key', ['admin']);
    $key = $this->service->authenticate($token);

    expect($key->hasScope('deploy'))->toBeTrue()
        ->and($key->hasScope('rollback'))->toBeTrue();
});
