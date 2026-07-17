<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the capability probe for admins', function () {
    $response = $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/system/capabilities')
        ->assertOk();

    $response->assertJsonStructure([
        'data' => [
            'php_version',
            'sapi',
            'extensions',
            'functions',
            'symlink_runtime',
            'limits' => ['upload_max_filesize', 'post_max_size'],
            'disk' => ['free', 'total'],
            'command_driver',
            'cron_token_configured',
            'allowed_base_paths',
        ],
    ]);

    expect($response->json('data.extensions.zip'))->toBeTrue()
        ->and($response->json('data.command_driver'))->toBe('cron-worker')
        ->and($response->json('data.symlink_runtime'))->toBeBool();
});

it('creates, lists and revokes an API key via the admin endpoints', function () {
    $this->actingAs(User::factory()->create());

    $create = $this->postJson('/api/v1/api-keys', [
        'name' => 'ci-token',
        'scopes' => ['deploy'],
    ])->assertCreated();

    $token = $create->json('token');
    expect($token)->toStartWith('cpk_');
    $create->assertJsonMissingPath('data.hash'); // hash never serialized

    $id = $create->json('data.id');

    $this->getJson('/api/v1/api-keys')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'ci-token');

    $this->deleteJson("/api/v1/api-keys/{$id}")->assertOk();

    // Revoked key no longer authenticates.
    expect(app(\App\Domain\Auth\ApiKeyService::class)->authenticate($token))->toBeNull();
});
