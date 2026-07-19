<?php

namespace App\Domain\Deploy;

use App\Adapters\Command\CommandRunner;
use App\Adapters\Storage\StorageAdapter;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Throwable;

/**
 * Orchestrates the deploy pipeline for a Deployment (docs/SPEC.md §6, §9).
 *
 * - No-hook projects (static / WordPress / plain PHP): run the whole pipeline synchronously
 *   in web PHP — lock → extract → link shared → validate → activate → health → prune.
 * - Hook projects (Laravel): the web/queue side STAGES (lock → extract → link → validate),
 *   sets status = hooks_pending and hands the lock off; the cron-worker (`cporter:run-jobs`)
 *   calls finalize() in shell context to run pre-activate hooks → activate → post-activate
 *   hooks → health → prune. A failed health check (or post-activate hook) auto-rolls back.
 */
class DeployEngine
{
    public function __construct(
        private readonly StorageAdapter $storage,
        private readonly RollbackEngine $rollback,
        private readonly HealthChecker $health,
        private readonly CommandRunner $commands,
    ) {}

    public function deploy(Deployment $deployment): Deployment
    {
        [$project, $release, $artifact] = $this->resolve($deployment);

        $deployment->forceFill([
            'status' => DeploymentStatus::Running,
            'started_at' => now(),
            'steps' => [],
        ])->save();

        $steps = new StepRunner($deployment);
        $locked = false;
        $handedOff = false;

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

            $steps->run('link_shared', fn () => $this->storage->linkShared($release->path, $this->sharedDir($project), $project->shared_paths ?? []));

            $steps->run('validate', function () use ($project, $release) {
                $this->validateStructure($project, $release->path);
                $release->forceFill(['state' => ReleaseState::Ready])->save();
            });

            if ($this->needsHooks($project)) {
                // Defer activation + hooks to the cron-worker; keep the lock held for it.
                $deployment->forceFill(['status' => DeploymentStatus::HooksPending])->save();
                $handedOff = true;

                return $deployment->refresh();
            }

            $this->runActivationPhase($steps, $project, $release, $deployment);
        } catch (Throwable) {
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
        } finally {
            if ($locked && ! $handedOff) {
                $this->storage->releaseLock($project->base_path);
            }
        }

        return $deployment->refresh();
    }

    /**
     * Finalize a hooks_pending deployment from the cron-worker's shell context (docs/SPEC.md §9).
     * The deploy lock is still held from staging; it is released here.
     */
    public function finalize(Deployment $deployment): Deployment
    {
        if ($deployment->status !== DeploymentStatus::HooksPending) {
            return $deployment;
        }

        [$project, $release] = $this->resolve($deployment);

        $deployment->forceFill(['status' => DeploymentStatus::Running])->save();
        $steps = new StepRunner($deployment);

        try {
            $this->runActivationPhase($steps, $project, $release, $deployment, hooks: true);
        } catch (Throwable) {
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();
        } finally {
            $this->storage->releaseLock($project->base_path);
        }

        return $deployment->refresh();
    }

    /**
     * Shared activation phase: (pre-hooks) → activate → (post-hooks) → health → prune, with
     * auto-rollback if anything after activation fails.
     */
    private function runActivationPhase(StepRunner $steps, Project $project, Release $release, Deployment $deployment, bool $hooks = false): void
    {
        $activated = false;

        try {
            if ($hooks) {
                $this->runHooks($steps, $project, $release, 'pre_activate');
            }

            $steps->run('activate', function () use ($release, $project) {
                $this->storage->activate($release->path, $this->currentLink($project));
                $this->supersedeOthers($project, $release);
                $release->forceFill(['state' => ReleaseState::Active, 'activated_at' => now()])->save();
            });
            $activated = true;

            if ($hooks) {
                $this->runHooks($steps, $project, $release, 'post_activate');
            }

            if (! $this->healthy($steps, $project)) {
                throw new DeployException("Health check failed: {$project->health_check_url}");
            }

            $steps->run('prune', fn () => $this->storage->pruneReleases($project->base_path, $project->keep_releases));
            $stats = $this->storage->diskStats($project->base_path);
            $project->forceFill([
                'disk_usage' => $stats['current'],
                'releases_disk_usage' => $stats['releases'],
                'disk_usage_calculated_at' => now(),
            ])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Success, 'finished_at' => now()])->save();
        } catch (Throwable $e) {
            if ($activated) {
                $this->autoRollback($project, $release, $deployment, $steps);

                return;
            }
            throw $e;
        }
    }

    private function runHooks(StepRunner $steps, Project $project, Release $release, string $phase): void
    {
        $hooks = $project->hooks[$phase] ?? [];
        if (empty($hooks)) {
            return;
        }

        if (! $this->commands->isAvailable()) {
            $message = "Shell is unavailable to run {$phase} hooks — run manually: ".implode('; ', $hooks);
            $steps->record("hook:{$phase}", false, $message);
            throw new DeployException($message);
        }

        $binary = $project->php_binary ?: 'php';

        foreach ($hooks as $hook) {
            $command = str_starts_with($hook, 'artisan ') ? $binary.' '.$hook : $hook;
            $steps->run("hook:{$phase}:{$hook}", function () use ($command, $release) {
                $result = $this->commands->run($command, $release->path, [], 600);
                if (! $result->ok()) {
                    throw new DeployException("Hook failed (exit {$result->exitCode}): {$command}\n{$result->output}");
                }
            });
        }
    }

    private function healthy(StepRunner $steps, Project $project): bool
    {
        if (! filled($project->health_check_url)) {
            return true;
        }

        $ok = $this->health->check($project->health_check_url, (int) config('cporter.health_check.timeout', 30));
        $steps->record('health_check', $ok, $ok ? null : "Health check failed: {$project->health_check_url}");

        return $ok;
    }

    private function autoRollback(Project $project, Release $release, Deployment $deployment, StepRunner $steps): void
    {
        $previous = $this->rollback->previousRelease($project, $release);

        if ($previous === null) {
            $release->forceFill(['state' => ReleaseState::Failed])->save();
            $deployment->forceFill(['status' => DeploymentStatus::Failed, 'finished_at' => now()])->save();

            return;
        }

        $steps->run('auto_rollback', fn () => $this->rollback->activateRelease($project, $previous));
        $release->forceFill(['state' => ReleaseState::Failed])->save();
        $deployment->forceFill(['status' => DeploymentStatus::RolledBack, 'finished_at' => now()])->save();
    }

    private function needsHooks(Project $project): bool
    {
        $hooks = $project->hooks ?? [];

        return ! empty($hooks['pre_activate']) || ! empty($hooks['post_activate']);
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

    /**
     * @return array{0: Project, 1: Release, 2: \App\Models\Artifact}
     */
    private function resolve(Deployment $deployment): array
    {
        $project = $deployment->project;
        $release = $deployment->release;
        $artifact = $release?->artifact;

        if (! $project instanceof Project || ! $release instanceof Release || $artifact === null) {
            throw new DeployException('Deployment is missing its project, release, or artifact.');
        }

        return [$project, $release, $artifact];
    }

    private function currentLink(Project $project): string
    {
        return rtrim($project->base_path, '/').'/current';
    }

    private function sharedDir(Project $project): string
    {
        return rtrim($project->base_path, '/').'/shared';
    }
}
