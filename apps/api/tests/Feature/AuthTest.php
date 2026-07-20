<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

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

it('primes the CSRF cookie via GET /csrf', function () {
    $this->get('/api/v1/csrf')->assertNoContent();
});

it('returns JSON 401 for unauthenticated api requests even without an Accept header', function () {
    // Regression: must not redirect to a non-existent `login` route (500). See bootstrap/app.php.
    $this->get('/api/v1/auth/user')->assertStatus(401);
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

it('lets a signed-in user change their own password', function () {
    $user = User::factory()->create(); // factory password = 'password'

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertOk()
        ->assertJsonPath('data', true);

    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('signs out other sessions only when the user opts in', function () {
    $user = User::factory()->create();

    // Prime a session that has gone through AuthenticateSession (stores the password hash).
    $this->actingAs($user)->getJson('/api/v1/auth/user')->assertOk();

    $this->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
        'logout_other_devices' => true,
    ])->assertOk();

    // The acting session was rehashed, so it survives its own logout-others call.
    $this->getJson('/api/v1/auth/user')->assertOk();
    expect(Hash::check('new-password', $user->fresh()->password))->toBeTrue();
});

it('rejects a password change when the current password is wrong', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('current_password');

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('rejects a password change when confirmation does not match', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'different',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');
});

it('requires authentication to change a password', function () {
    $this->putJson('/api/v1/auth/password', [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertUnauthorized();
});
