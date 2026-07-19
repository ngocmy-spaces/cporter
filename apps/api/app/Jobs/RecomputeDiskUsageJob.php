<?php

namespace App\Jobs;

use App\Adapters\Storage\StorageAdapter;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Recomputes a project's on-disk footprint (docs/SPEC.md §11) off the request — walking the
 * release tree can be slow. Triggered from the UI (ProjectController::recomputeDiskUsage) or,
 * inline, after each deploy's prune. The project's `disk_usage_status` flag lets the UI poll
 * to completion and makes repeat triggers idempotent.
 */
class RecomputeDiskUsageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public readonly Project $project) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        // Belt-and-suspenders against a double dispatch racing the DB flag: drop the duplicate.
        return [(new WithoutOverlapping('disk-usage-'.$this->project->id))->dontRelease()];
    }

    public function handle(StorageAdapter $storage): void
    {
        $stats = $storage->diskStats($this->project->base_path);
        $sharedSizes = $storage->sharedPathSizes($this->project->base_path, $this->project->shared_paths ?? []);

        $this->project->forceFill([
            'disk_usage' => $stats['current'],
            'releases_disk_usage' => $stats['releases'],
            'shared_disk_usage' => $sharedSizes,
            'disk_usage_status' => 'idle',
            'disk_usage_calculated_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        // Never leave the flag stuck on 'running' — clear it so the user can retry.
        $this->project->forceFill(['disk_usage_status' => 'idle'])->save();
    }
}
