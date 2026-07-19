<?php

use App\Domain\Storage\PathJail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->base = sys_get_temp_dir().'/cporter_p3_'.uniqid();
    File::makeDirectory($this->base, 0777, true, true);
    config(['cporter.allowed_base_paths' => [$this->base]]);
    app()->forgetInstance(PathJail::class);

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->viewer = User::factory()->viewer()->create();
});

afterEach(fn () => File::deleteDirectory($this->base));

// ── T3.4 Audit ──────────────────────────────────────────────────────────────

it('records an audit entry on project creation and lists it', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Audited', 'base_path' => $this->base, 'type' => 'static',
    ])->assertCreated();

    expect(AuditLog::where('action', 'project.created')->exists())->toBeTrue();

    $this->actingAs($this->admin)->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'project.created')
        ->assertJsonPath('data.0.actor', $this->admin->email);
});

it('returns a project-scoped activity feed, isolated per project', function () {
    $other = sys_get_temp_dir().'/cporter_p3_other_'.uniqid();
    File::makeDirectory($other, 0777, true, true);
    config(['cporter.allowed_base_paths' => [$this->base, $other]]);
    app()->forgetInstance(PathJail::class);

    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Alpha', 'base_path' => $this->base, 'type' => 'static',
    ])->assertCreated();
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Beta', 'base_path' => $other, 'type' => 'static',
    ])->assertCreated();

    // Add a second event to alpha so its feed has more than one entry.
    $this->actingAs($this->admin)->patchJson('/api/v1/projects/alpha', ['name' => 'Alpha 2'])->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/projects/alpha/activity')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'project.updated')
        ->assertJsonPath('data.1.action', 'project.created')
        ->assertJsonCount(2, 'data');

    File::deleteDirectory($other);
});

it('filters the project activity feed by action', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Gamma', 'base_path' => $this->base, 'type' => 'static',
    ])->assertCreated();
    $this->actingAs($this->admin)->postJson('/api/v1/projects/gamma/preflight')->assertOk();

    $this->actingAs($this->admin)->getJson('/api/v1/projects/gamma/activity?action=project.preflight')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.action', 'project.preflight');
});

it('lets a viewer read a project activity feed', function () {
    $this->actingAs($this->admin)->postJson('/api/v1/projects', [
        'name' => 'Delta', 'base_path' => $this->base, 'type' => 'static',
    ])->assertCreated();

    $this->actingAs($this->viewer)->getJson('/api/v1/projects/delta/activity')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'project.created');
});

// ── T3.2 Roles ──────────────────────────────────────────────────────────────

it('lets a viewer read but not write', function () {
    // read OK
    $this->actingAs($this->viewer)->getJson('/api/v1/projects')->assertOk();
    // write denied
    $this->actingAs($this->viewer)->postJson('/api/v1/projects', [
        'name' => 'Nope', 'base_path' => $this->base, 'type' => 'static',
    ])->assertStatus(403);
});

it('manages users (admin only) and blocks self-deletion', function () {
    $created = $this->actingAs($this->admin)->postJson('/api/v1/users', [
        'name' => 'Deploy Bot', 'email' => 'bot@cporter.local', 'password' => 'password123', 'role' => 'viewer',
    ])->assertCreated()->json('data');

    expect($created['role'])->toBe('viewer');

    // viewer cannot manage users
    $this->actingAs($this->viewer)->getJson('/api/v1/users')->assertStatus(403);

    // cannot delete self
    $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$this->admin->id}")->assertStatus(422);
    // can delete another
    $this->actingAs($this->admin)->deleteJson("/api/v1/users/{$created['id']}")->assertOk();
});

// ── T3.6 Webhooks ────────────────────────────────────────────────────────────

it('verifies a GitHub webhook HMAC signature', function () {
    config(['cporter.webhook_secret' => 's3cr3t']);
    $payload = '{"ref":"refs/heads/main"}';
    $sig = 'sha256='.hash_hmac('sha256', $payload, 's3cr3t');

    $this->call('POST', '/api/v1/webhooks/github', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X-HUB-SIGNATURE-256' => $sig,
        'HTTP_X-GITHUB-EVENT' => 'push',
        'CONTENT_TYPE' => 'application/json',
    ], $payload)->assertStatus(202);

    expect(AuditLog::where('action', 'webhook.received')->exists())->toBeTrue();
});

it('rejects a GitHub webhook with a bad signature', function () {
    config(['cporter.webhook_secret' => 's3cr3t']);

    $this->call('POST', '/api/v1/webhooks/github', [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X-HUB-SIGNATURE-256' => 'sha256=deadbeef',
        'CONTENT_TYPE' => 'application/json',
    ], '{"ref":"x"}')->assertStatus(401);
});

it('returns 503 when webhooks are not configured', function () {
    config(['cporter.webhook_secret' => null]);

    $this->postJson('/api/v1/webhooks/gitlab', [])->assertStatus(503);
});
