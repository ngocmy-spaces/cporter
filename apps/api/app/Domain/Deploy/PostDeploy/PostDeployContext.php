<?php

namespace App\Domain\Deploy\PostDeploy;

use App\Domain\Deploy\StepRunner;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\Release;

/**
 * Everything a post-deploy task needs about the deploy that just succeeded (docs/SPEC.md §6).
 * Passed to each {@see PostDeployTask}; tasks record their own step via $steps.
 */
class PostDeployContext
{
    public function __construct(
        public readonly Project $project,
        public readonly Release $release,
        public readonly Deployment $deployment,
        public readonly StepRunner $steps,
    ) {}
}
