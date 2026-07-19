<?php

use App\Domain\System\CronHeartbeat;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports unknown when the cron has never run', function () {
    $status = app(CronHeartbeat::class)->status();

    expect($status['state'])->toBe('unknown')
        ->and($status['mode'])->toBeNull();
});

it('reports Mode B healthy from a fresh worker heartbeat', function () {
    Setting::write(CronHeartbeat::WORKER_KEY, [
        'at' => now()->toIso8601String(),
        'host' => 'test-host',
        'passes' => 7,
    ]);

    $status = app(CronHeartbeat::class)->status();

    expect($status['state'])->toBe('healthy')
        ->and($status['mode'])->toBe('B')
        ->and($status['passes'])->toBe(7)
        ->and($status['age_seconds'])->toBeLessThan(5);
});

it('reports Mode A healthy from a fresh tick heartbeat', function () {
    Setting::write(CronHeartbeat::TICK_KEY, ['at' => now()->toIso8601String()]);

    expect(app(CronHeartbeat::class)->status()['mode'])->toBe('A');
});

it('reports down when the last heartbeat is stale', function () {
    Setting::write(CronHeartbeat::WORKER_KEY, ['at' => now()->subMinutes(10)->toIso8601String()]);

    $status = app(CronHeartbeat::class)->status();

    expect($status['state'])->toBe('down')
        ->and($status['mode'])->toBe('B');
});

it('serves cron status over the admin endpoint', function () {
    Setting::write(CronHeartbeat::WORKER_KEY, ['at' => now()->toIso8601String(), 'passes' => 3]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/system/cron')
        ->assertOk()
        ->assertJsonPath('data.state', 'healthy')
        ->assertJsonPath('data.mode', 'B');
});
