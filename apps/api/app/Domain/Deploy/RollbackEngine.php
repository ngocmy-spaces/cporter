<?php

namespace App\Domain\Deploy;

use App\Adapters\Storage\StorageAdapter;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ReleaseState;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Throwable;

/**
 * Code-only rollback: re-point `current` at a previous (or specified) release
 * (docs/SPEC.md §8). Shared by the manual rollback endpoint and the deploy engine's
 * auto-rollback on failed health check.
 */
class RollbackEngine
{
    public function __construct(private readonly StorageAdapter $storage) {}

    /** The most recently superseded release (the one active before the current), if any. */
    public function previousRelease(Project $project, ?Release $except = null): ?Release
    {
        return Release::query()
            ->where('project_id', $project->id)
            ->where('state', ReleaseState::Superseded->value)
            ->when($except !== null, fn ($q) => $q->where('id', '!=', $except->id))
            ->orderByDesc('activated_at')
            ->orderByDesc('id')
            ->first();
    }

    /** Low-level: swap `current` to $target and fix release states (no deployment record). */
    public function activateRelease(Project $project, Release $target): void
    {
        if (! is_dir($target->path)) {
            throw new DeployException('Target release directory is missing on disk: '.$target->path);
        }

        $current = rtrim($project->base_path, '/').'/current';
        $this->storage->activate($target->path, $current);

        Release::query()
            ->where('project_id', $project->id)
            ->where('state', ReleaseState::Active->value)
            ->where('id', '!=', $target->id)
            ->update(['state' => ReleaseState::Superseded->value]);

        $target->forceFill(['state' => ReleaseState::Active, 'activated_at' => now()])->save();
    }

    /** Manual rollback: records a Deployment for the action. */
    public function rollback(Project $project, ?Release $target, DeploymentTrigger $trigger, ?string $actor): Deployment
    {
        $target ??= $this->previousRelease($project);

        if ($target === null) {
            throw new DeployException('No previous release to roll back to.');
        }
        if ($target->project_id !== $project->id) {
            throw new DeployException('Release does not belong to this project.');
        }

        $deployment = Deployment::create([
            'project_id' => $project->id,
            'release_id' => $target->id,
            'trigger' => $trigger,
            'status' => DeploymentStatus::Running,
            'actor' => $actor,
            'started_at' => now(),
        ]);

        $steps = new StepRunner($deployment);
        $locked = false;

        try {
            $steps->run('lock', function () use ($project, &$locked) {
                if (! $this->storage->acquireLock($project->base_path)) {
                    throw new DeployException('Project is locked by another deployment.');
                }
                $locked = true;
            });
            $steps->run('activate', fn () => $this->activateRelease($project, $target));

            $deployment->forceFill(['status' => DeploymentStatus::Success, 'finished_at' => now()])->save();
        } catch (Throwable) {
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
        } finally {
            if ($locked) {
                $this->storage->releaseLock($project->base_path);
            }
        }

        return $deployment->refresh();
    }
}
