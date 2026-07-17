<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('logs in with valid credentials', function () {
    User::factory()->create(['email' => 'admin@cporter.local']); // factory password = 'password'

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@cporter.local',
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonPath('data.email', 'admin@cporter.local');
});

it('rejects invalid credentials with 422', function () {
    User::factory()->create(['email' => 'admin@cporter.local']);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@cporter.local',
        'password' => 'wrong-password',
    ])->assertStatus(422);
});

it('requires authentication for protected admin endpoints', function () {
    $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    $this->getJson('/api/v1/system/capabilities')->assertUnauthorized();
    $this->getJson('/api/v1/api-keys')->assertUnauthorized();
});

it('returns the authenticated admin user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/v1/auth/user')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);
});
