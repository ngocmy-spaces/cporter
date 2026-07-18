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
 * This handles the synchronous, no-shell path used by static / WordPress / plain-PHP
 * projects: lock → extract → link shared → validate → activate → prune → success.
 * Health check + auto-rollback are T1.6; Laravel pre/post-activate hooks (cron-worker)
 * are Phase 2. Every step is recorded to Deployment.steps as it runs (for polling).
 */
class DeployEngine
{
    public function __construct(private readonly StorageAdapter $storage) {}

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

        $steps = [];
        $locked = false;

        $run = function (string $name, callable $fn) use (&$steps, $deployment): void {
            $start = microtime(true);
            try {
                $fn();
            } catch (Throwable $e) {
                $steps[] = $this->step($name, 'failed', $start, $e->getMessage());
                $deployment->forceFill(['steps' => $steps])->save();
                throw $e;
            }
            $steps[] = $this->step($name, 'success', $start);
            $deployment->forceFill(['steps' => $steps])->save();
        };

        try {
            $run('lock', function () use ($project, &$locked) {
                if (! $this->storage->acquireLock($project->base_path)) {
                    throw new DeployException('Project is locked by another deployment.');
                }
                $locked = true;
            });

            $run('extract', function () use ($artifact, $release) {
                $release->forceFill(['state' => ReleaseState::Extracting])->save();
                $this->storage->extractZip((string) $artifact->storage_path, $release->path);
            });

            $run('link_shared', function () use ($release, $shared, $project) {
                $this->storage->linkShared($release->path, $shared, $project->shared_paths ?? []);
            });

            $run('validate', function () use ($project, $release) {
                $this->validateStructure($project, $release->path);
                $release->forceFill(['state' => ReleaseState::Ready])->save();
            });

            $run('activate', function () use ($release, $current, $project) {
                $this->storage->activate($release->path, $current);

                Release::query()
                    ->where('project_id', $project->id)
                    ->where('state', ReleaseState::Active->value)
                    ->where('id', '!=', $release->id)
                    ->update(['state' => ReleaseState::Superseded->value]);

                $release->forceFill(['state' => ReleaseState::Active, 'activated_at' => now()])->save();
            });

            $run('prune', function () use ($project) {
                $this->storage->pruneReleases($project->base_path, $project->keep_releases);
            });

            $deployment->forceFill(['status' => DeploymentStatus::Success, 'finished_at' => now()])->save();
        } catch (Throwable) {
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
            // Error is already recorded in steps; the deployment is marked failed for polling.
        } finally {
            if ($locked) {
                $this->storage->releaseLock($project->base_path);
            }
        }

        return $deployment->refresh();
    }

    private function validateStructure(Project $project, string $releaseDir): void
    {
        $sub = trim((string) $project->docroot_subpath, '/');
        $docroot = rtrim($releaseDir.'/'.$sub, '/');

        if (! is_dir($docroot)) {
            throw new DeployException('Docroot not found in release: '.($sub !== '' ? $sub : '.'));
        }
    }

    /**
     * @return array{name: string, status: string, duration_ms: int, error?: string}
     */
    private function step(string $name, string $status, float $start, ?string $error = null): array
    {
        $step = [
            'name' => $name,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
        ];

        if ($error !== null) {
            $step['error'] = $error;
        }

        return $step;
    }
}
