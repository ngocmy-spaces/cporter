<?php

namespace App\Domain\Deploy\PostDeploy;

/**
 * A unit of work that runs AFTER a deploy has succeeded (activated + healthy) — cleanup,
 * notifications, external triggers, etc. (docs/SPEC.md §6).
 *
 * Contract:
 *   - name() is the pipeline step key recorded on the deployment (add a matching label to the
 *     frontend PIPELINE so it renders nicely).
 *   - handle() records its own step(s) via $ctx->steps and may throw; the {@see PostDeployRunner}
 *     treats any failure as NON-FATAL — the deploy is already successful, so a failed post-deploy
 *     task is surfaced on the timeline but never flips the deployment to failed.
 *
 * To add a task (e.g. email/webhook), implement this and register it in config('cporter.post_deploy_tasks').
 */
interface PostDeployTask
{
    public function name(): string;

    public function handle(PostDeployContext $ctx): void;
}
