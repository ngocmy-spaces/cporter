<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores and reads settings with JSON values', function () {
    Setting::write('demo', ['a' => 1, 'b' => [2, 3]]);

    expect(Setting::read('demo'))->toBe(['a' => 1, 'b' => [2, 3]])
        ->and(Setting::read('missing', 'fallback'))->toBe('fallback');
});

it('upserts on repeated writes', function () {
    Setting::write('demo', ['v' => 1]);
    Setting::write('demo', ['v' => 2]);

    expect(Setting::read('demo'))->toBe(['v' => 2])
        ->and(Setting::query()->where('key', 'demo')->count())->toBe(1);
});

it('persists the capability probe on GET and refreshes on POST', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->getJson('/api/v1/system/capabilities')
        ->assertOk()
        ->assertJsonPath('data.command_driver', 'cron-worker');

    expect(Setting::read('capabilities'))->not->toBeNull();

    $this->actingAs($user)->postJson('/api/v1/system/capabilities/refresh')
        ->assertOk()
        ->assertJsonStructure(['data', 'probed_at']);
});
