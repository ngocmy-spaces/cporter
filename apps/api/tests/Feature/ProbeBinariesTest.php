<?php

use App\Adapters\Command\CommandRunner;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeCommandRunner;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->cmd = new FakeCommandRunner;
    app()->instance(CommandRunner::class, $this->cmd);
});

it('probes hook binaries via the shell and caches the result', function () {
    Artisan::call('cporter:probe-binaries');

    $stored = Setting::read('binaries');

    expect($stored)->toHaveKeys(['result', 'detected_at'])
        ->and($stored['result'])->toHaveKeys(['php', 'composer', 'node', 'npm', 'python3']);

    // Each name was resolved with `command -v` in the shell context.
    $probes = collect($this->cmd->ran)->pluck('command');
    expect($probes)->toContain("command -v 'php'")
        ->and($probes)->toContain("command -v 'composer'");
});

it('skips probing when the shell is unavailable', function () {
    $this->cmd->available = false;

    Artisan::call('cporter:probe-binaries');

    expect(Setting::read('binaries'))->toBeNull()
        ->and($this->cmd->ran)->toBe([]);
});

it('is self-throttled: a fresh cached result is not re-probed unless forced', function () {
    Setting::write('binaries', [
        'result' => ['php' => '/usr/bin/php'],
        'detected_at' => now()->toIso8601String(),
    ]);

    Artisan::call('cporter:probe-binaries');
    expect($this->cmd->ran)->toBe([]); // fresh → no shell calls

    Artisan::call('cporter:probe-binaries', ['--force' => true]);
    expect($this->cmd->ran)->not->toBe([]); // forced → re-probes
});

it('re-probes a stale cached result', function () {
    Setting::write('binaries', [
        'result' => ['php' => '/usr/bin/php'],
        'detected_at' => now()->subHours(12)->toIso8601String(),
    ]);

    Artisan::call('cporter:probe-binaries');

    expect($this->cmd->ran)->not->toBe([]);
});

it('overlays the cron-probed binaries onto /system/capabilities as authoritative', function () {
    Setting::write('binaries', [
        'result' => ['php' => '/opt/cpanel/ea-php83/root/usr/bin/php', 'node' => null],
        'detected_at' => '2026-07-20T00:00:00+00:00',
    ]);

    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/system/capabilities')
        ->assertOk()
        ->assertJsonPath('data.binaries_source', 'cron')
        ->assertJsonPath('data.binaries_detected_at', '2026-07-20T00:00:00+00:00')
        ->assertJsonPath('data.binaries.php', '/opt/cpanel/ea-php83/root/usr/bin/php')
        ->assertJsonPath('data.binaries.node', null);
});

it('falls back to the web PATH scan when the cron-worker has not probed yet', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/api/v1/system/capabilities')
        ->assertOk()
        ->assertJsonPath('data.binaries_source', 'path-scan')
        ->assertJsonStructure(['data' => ['binaries', 'binaries_detected_at']]);
});
