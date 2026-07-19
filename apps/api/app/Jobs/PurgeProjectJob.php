<?php

namespace App\Jobs;

use App\Adapters\Storage\StorageAdapter;
use App\Domain\Audit\AuditLogger;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Purges a deleted project's disk footprint off the request (docs/SPEC.md §5, §11) — walking and
 * removing a release tree can be slow. Dispatched by ProjectController::destroy when the admin
 * opts into disk reclamation. The project sits in the `deleting` status while this runs, then is
 * soft-deleted (hidden) on success. Keyed WithoutOverlapping the deploy lock's project so a purge
 * never races a deploy of the same project.
 *
 * @see StorageAdapter::purgeProject()
 */
class PurgeProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    /** @param  'releases'|'all'  $level */
    public function __construct(
        public readonly Project $project,
        public readonly string $level,
    ) {}

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('project-'.$this->project->id))->dontRelease()];
    }

    public function handle(StorageAdapter $storage, AuditLogger $audit): void
    {
        $freed = $storage->purgeProject($this->project->base_path, $this->level);

        // Success: hide the project. The soft-deleted row keeps deploy/audit history intact.
        $this->project->delete();

        $audit->record('project.deleted', $this->project, [
            'slug' => $this->project->slug,
            'purge' => $this->level,
            'bytes_freed' => $freed,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        // Never leave a project stuck in `deleting` if the purge dies. Park it as `disabled`
        // (deploys stay blocked) so an admin can inspect and retry.
        $this->project->forceFill(['status' => ProjectStatus::Disabled])->save();

        app(AuditLogger::class)->record('project.delete_failed', $this->project, [
            'slug' => $this->project->slug,
            'purge' => $this->level,
            'error' => $e?->getMessage(),
        ]);
    }
}
