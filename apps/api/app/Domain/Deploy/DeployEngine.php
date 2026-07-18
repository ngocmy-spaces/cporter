<?php

namespace App\Domain\Deploy;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Throwable;

/**
 * Orchestrates the deploy pipeline for a Deployment (docs/SPEC.md §6).
 *
 * Synchronous, no-shell path for static / WordPress / plain-PHP projects:
 * lock → extract → link shared → validate → activate → health check → prune → success.
 * A failed health check triggers auto-rollback to the previous release (§8).
 * Laravel pre/post-activate hooks (cron-worker) are Phase 2.
 */
class DeployEngine
{
    public function __construct(
        private readonly StorageAdapter $storage,
        private readonly RollbackEngine $rollback,
        private readonly HealthChecker $health,
    ) {}

    public function deploy(Deployment $deployment): Deployment
    {
        $project = $deployment->project;
        $release = $deployment->release;
        $artifact = $release?->artifact;

        if (! $project instanceof Project || ! $release instanceof Release || $artifact === null) {
            throw new DeployException('Deployment is missing its project, release, or artifact.');
        }

        $base = rtrim($project->base_path, '/');
        $current = $base.'/current';
        $shared = $base.'/shared';

        $deployment->forceFill([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
            'steps' => [],
        ])->save();

        $steps = new StepRunner($deployment);
        $locked = false;

        try {
            $steps->run('lock', function () use ($project, &$locked) {
                if (! $this->storage->acquireLock($project->base_path)) {
                    throw new DeployException('Project is locked by another deployment.');
                }
                $locked = true;
            });

            $steps->run('extract', function () use ($artifact, $release) {
                $release->forceFill(['state' => ReleaseState::Extracting])->save();
                $this->storage->extractZip((string) $artifact->storage_path, $release->path);
            });

            $steps->run('link_shared', fn () => $this->storage->linkShared($release->path, $shared, $project->shared_paths ?? []));

            $steps->run('validate', function () use ($project, $release) {
                $this->validateStructure($project, $release->path);
                $release->forceFill(['state' => ReleaseState::Ready])->save();
            });

            $steps->run('activate', function () use ($release, $current, $project) {
                $this->storage->activate($release->path, $current);
                $this->supersedeOthers($project, $release);
                $release->forceFill(['state' => ReleaseState::Active, 'activated_at' => now()])->save();
            });

            // Health check + auto-rollback (docs/SPEC.md §6, §8).
            $healthy = true;
            if (filled($project->health_check_url)) {
                $healthy = $this->health->check(
                    $project->health_check_url,
                    (int) config('cporter.health_check.timeout', 30),
                );
                $steps->record('health_check', $healthy, $healthy ? null : "Health check failed: {$project->health_check_url}");
            }

            if ($healthy) {
                $steps->run('prune', fn () => $this->storage->pruneReleases($project->base_path, $project->keep_releases));
                $deployment->forceFill(['status' => DeploymentStatus::Success, 'finished_at' => now()])->save();
            } else {
                $this->autoRollback($project, $release, $deployment, $steps);
            }
        } catch (Throwable) {
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
        } finally {
            if ($locked) {
                $this->storage->releaseLock($project->base_path);
            }
        }

        return $deployment->refresh();
    }

    private function autoRollback(Project $project, Release $release, Deployment $deployment, StepRunner $steps): void
    {
        $previous = $this->rollback->previousRelease($project, $release);

        if ($previous === null) {
            // Nothing to roll back to (first deploy) — leave current as-is, mark failed.
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();

            return;
        }

        $steps->run('auto_rollback', fn () => $this->rollback->activateRelease($project, $previous));
        $release->forceFill(['state' => ReleaseState::Failed])->save();
        $deployment->forceFill(['status' => DeploymentStatus::RolledBack, 'finished_at' => now()])->save();
    }

    private function supersedeOthers(Project $project, Release $release): void
    {
        Release::query()
            ->where('project_id', $project->id)
            ->where('state', ReleaseState::Active->value)
            ->where('id', '!=', $release->id)
            ->update(['state' => ReleaseState::Superseded->value]);
    }

    private function validateStructure(Project $project, string $releaseDir): void
    {
        $sub = trim((string) $project->docroot_subpath, '/');
        $docroot = rtrim($releaseDir.'/'.$sub, '/');

        if (! is_dir($docroot)) {
            throw new DeployException('Docroot not found in release: '.($sub !== '' ? $sub : '.'));
        }
    }
}
