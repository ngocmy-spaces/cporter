<?php

namespace App\Domain\Deploy\PostDeploy;

use App\Domain\Deploy\StepRunner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs the configured post-deploy tasks in order after a successful deploy (docs/SPEC.md §6).
 *
 * Tasks are resolved from config('cporter.post_deploy_tasks') via the container, so shipping a
 * new one (notify, webhook, …) is a one-line config addition. Every task is isolated: a task
 * that throws is caught here and recorded, never propagating — the deploy already succeeded, so
 * cleanup/notification failures must not fail it.
 */
class PostDeployRunner
{
    public function __construct(private readonly Container $container) {}

    public function run(StepRunner $steps, Project $project, Release $release, Deployment $deployment): void
    {
        $ctx = new PostDeployContext($project, $release, $deployment, $steps);

        foreach ((array) config('cporter.post_deploy_tasks', []) as $taskClass) {
            try {
                $task = $this->container->make($taskClass);
                if ($task instanceof PostDeployTask) {
                    $task->handle($ctx);
                }
            } catch (Throwable $e) {
                // Non-fatal: the task's own StepRunner->run already recorded a failed step; if the
                // failure happened before that, leave a warning so the timeline still reflects it.
                $steps->warn(is_string($taskClass) ? class_basename($taskClass) : 'post_deploy', $e->getMessage());
            }
        }
    }
}
