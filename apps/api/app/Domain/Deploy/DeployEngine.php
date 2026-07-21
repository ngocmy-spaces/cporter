<?php

namespace App\Domain\Deploy;

use App\Adapters\Command\CommandRunner;
use App\Adapters\Storage\StorageAdapter;
use App\Domain\Deploy\PostDeploy\PostDeployRunner;
use App\Enums\DeploymentStatus;
use App\Enums\ReleaseState;
use App\Models\Artifact;
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
        private readonly ReleasePruner $releasePruner,
        private readonly PostDeployRunner $postDeploy,
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

            $sharedPaths = $this->writeEnv($steps, $project);

            $steps->run('link_shared', fn () => $this->storage->linkShared($release->path, $this->sharedDir($project), $sharedPaths));

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

            $steps->run('prune', fn () => $this->releasePruner->prune($project, $project->keep_releases));
            $stats = $this->storage->diskStats($project->base_path);
            $project->forceFill([
                'disk_usage' => $stats['current'],
                'releases_disk_usage' => $stats['releases'],
                'shared_disk_usage' => $this->storage->sharedPathSizes($project->base_path, $project->shared_paths ?? []),
                'disk_usage_calculated_at' => now(),
            ])->save();

            // The deploy is done + stable. Mark it successful, then run non-fatal post-deploy tasks
            // (artifact cleanup today; notifications/triggers can be added via config later).
            $deployment->forceFill(['status' => DeploymentStatus::Success])->save();
            $this->postDeploy->run($steps, $project, $release, $deployment);
            $deployment->forceFill(['finished_at' => now()])->save();
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

        foreach ($hooks as $hook) {
            $steps->run("hook:{$phase}:{$hook}", function () use ($hook, $release): ?string {
                $result = $this->commands->run($hook, $release->path, [], 600);
                if (! $result->ok()) {
                    throw new DeployException("Hook failed (exit {$result->exitCode}): {$hook}\n{$result->output}");
                }

                // Keep the hook's stdout/stderr on the successful step too — otherwise a hook that
                // "succeeds" but does nothing (e.g. `migrate` running under the wrong PHP → "Nothing
                // to migrate") is invisible. Tail-capped so a chatty hook can't bloat the steps JSON.
                return $this->tail($result->output);
            });
        }
    }

    /** Last ~2KB of a hook's output, for the step note (null when empty). */
    private function tail(string $output, int $max = 2000): ?string
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }

        return strlen($output) > $max ? '…'.substr($output, -$max) : $output;
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
     * @return array{0: Project, 1: Release, 2: Artifact}
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

    /**
     * Render the project's managed env vars into shared/.env before link_shared (docs/SPEC.md §9),
     * so the running app — and hooks like `php artisan config:cache` — see it. Never fails the
     * deploy: an existing hand-created (unmanaged) shared/.env is left untouched and the step is
     * recorded as a warning. Returns the shared_paths to link, with `.env` ensured present only
     * when cPorter actually wrote the file (so its own file gets symlinked into the release).
     *
     * @return list<array{path: string, type: string}>
     */
    private function writeEnv(StepRunner $steps, Project $project): array
    {
        $sharedPaths = $project->shared_paths ?? [];
        $envVars = $project->env_vars ?? [];

        if ($envVars === []) {
            return $sharedPaths;
        }

        $result = $this->storage->writeSharedFile(
            $this->sharedDir($project),
            '.env',
            EnvFileRenderer::render($envVars),
            EnvFileRenderer::MARKER,
        );

        if ($result === 'written') {
            $steps->record('write_env', true);
            if (! $this->hasSharedPath($sharedPaths, '.env')) {
                $sharedPaths[] = ['path' => '.env', 'type' => 'file'];
            }
        } else { // skipped_unmanaged — respect the operator's existing file, keep their shared_paths as-is
            $steps->warn('write_env',
                'shared/.env exists and is not managed by cPorter — kept the existing file and skipped '
                .'writing env vars. Use "Let cPorter manage this file" in the Environment tab to take it over.');
        }

        return $sharedPaths;
    }

    /**
     * @param  list<mixed>  $sharedPaths
     */
    private function hasSharedPath(array $sharedPaths, string $rel): bool
    {
        foreach ($sharedPaths as $entry) {
            $path = is_string($entry) ? $entry : (is_array($entry) ? ($entry['path'] ?? null) : null);
            if (is_string($path) && trim($path) === $rel) {
                return true;
            }
        }

        return false;
    }
}
