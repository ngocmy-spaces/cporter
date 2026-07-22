<?php

use App\Enums\ProjectHealth;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Single-shot monitor poll (no retry loop) so one sweep across every project stays fast.
beforeEach(fn () => config(['cporter.health_check.monitor_timeout' => 0]));

it('marks a project healthy when its health_check_url returns 2xx', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $project = Project::factory()->create(['health_check_url' => 'https://up.test/health']);

    $this->artisan('cporter:check-health')->assertSuccessful();

    $fresh = $project->fresh();
    expect($fresh->health_status)->toBe(ProjectHealth::Healthy)
        ->and($fresh->health_checked_at)->not->toBeNull()
        ->and($fresh->health_last_ok_at)->not->toBeNull();
});

it('marks a project unhealthy when its health_check_url fails', function () {
    Http::fake(['*' => Http::response('', 503)]);

    $project = Project::factory()->create(['health_check_url' => 'https://down.test/health']);

    $this->artisan('cporter:check-health')->assertSuccessful();

    $fresh = $project->fresh();
    expect($fresh->health_status)->toBe(ProjectHealth::Unhealthy)
        ->and($fresh->health_checked_at)->not->toBeNull()
        // Never passed → last_ok stays null.
        ->and($fresh->health_last_ok_at)->toBeNull();
});

it('marks a project without a health_check_url as unknown', function () {
    $project = Project::factory()->create(['health_check_url' => null]);

    $this->artisan('cporter:check-health')->assertSuccessful();

    expect($project->fresh()->health_status)->toBe(ProjectHealth::Unknown);
});

it('marks a disabled project as paused without polling it', function () {
    Http::fake(['*' => Http::response('', 200)]);

    $project = Project::factory()->create([
        'status' => ProjectStatus::Disabled,
        'health_check_url' => 'https://up.test/health',
    ]);

    $this->artisan('cporter:check-health')->assertSuccessful();

    expect($project->fresh()->health_status)->toBe(ProjectHealth::Paused);
    Http::assertNothingSent();
});

it('preserves health_last_ok_at across a later failure', function () {
    // First pass healthy, second pass unhealthy: last_ok must be retained from the healthy pass.
    Http::fakeSequence()->pushStatus(200)->pushStatus(500);

    $project = Project::factory()->create(['health_check_url' => 'https://flappy.test/health']);

    $this->artisan('cporter:check-health')->assertSuccessful();
    $okAt = $project->fresh()->health_last_ok_at;
    expect($okAt)->not->toBeNull();

    $this->artisan('cporter:check-health')->assertSuccessful();
    $fresh = $project->fresh();
    expect($fresh->health_status)->toBe(ProjectHealth::Unhealthy)
        ->and($fresh->health_last_ok_at?->toIso8601String())->toBe($okAt?->toIso8601String());
});
