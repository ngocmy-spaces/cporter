<?php

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use App\Domain\System\CronHeartbeat;
use App\Enums\DeploymentStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('runs a single pass with --duration=0 and finalizes work via its sub-commands', function () {
    // A timed-out deployment that cporter:housekeep (invoked inside the loop) should fail.
    $base = sys_get_temp_dir().'/cporter_work_'.uniqid();
    mkdir($base, 0777, true);
    config(['cporter.allowed_base_paths' => [$base]]);
    app()->forgetInstance(PathJail::class);
    app()->forgetInstance(StorageAdapter::class);

    $project = Project::factory()->create(['slug' => 'demo', 'base_path' => $base]);
    $stuck = Deployment::create([
        'project_id' => $project->id,
        'status' => DeploymentStatus::Running,
        'started_at' => now()->subHour(),
    ]);

    $code = Artisan::call('cporter:work', ['--duration' => 0, '--sleep' => 1]);

    expect($code)->toBe(0)
        ->and(Deployment::find($stuck->id)->status)->toBe(DeploymentStatus::Failed);

    // The worker records a Mode-B heartbeat; the nested run-jobs must NOT record a Mode-A tick.
    $status = app(CronHeartbeat::class)->status();
    expect($status['mode'])->toBe('B')
        ->and($status['state'])->toBe('healthy')
        ->and(Setting::read(CronHeartbeat::TICK_KEY))->toBeNull();

    rmdir($base);
});

it('exits immediately when another worker holds the lock', function () {
    $lock = Cache::lock('cporter:work', 60);
    expect($lock->get())->toBeTrue();

    try {
        $code = Artisan::call('cporter:work', ['--duration' => 5, '--sleep' => 1]);
        expect($code)->toBe(0)
            ->and(Artisan::output())->toContain('another worker holds the lock');
    } finally {
        $lock->release();
    }
});
