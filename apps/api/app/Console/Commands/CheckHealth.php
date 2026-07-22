<?php

namespace App\Console\Commands;

use App\Domain\Deploy\ProjectHealthMonitor;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

/**
 * Continuously monitor project health (docs/SPEC.md §21.1). Driven by the scheduler (~every
 * 1–5 min), it re-evaluates every non-deleting project and persists the result to
 * `projects.health_status`, the single source the dashboard/alerts read. Disabled projects
 * become `paused` and URL-less ones `unknown`; only a failing health_check_url is `unhealthy`.
 */
class CheckHealth extends Command
{
    protected $signature = 'cporter:check-health';

    protected $description = 'Poll each project\'s health_check_url and persist the result to health_status';

    public function handle(ProjectHealthMonitor $monitor): int
    {
        // Skip projects mid-deletion — their base_path may already be gone. Soft-deleted projects
        // are excluded by the default scope.
        $projects = Project::query()
            ->where('status', '!=', ProjectStatus::Deleting->value)
            ->get();

        $unhealthy = 0;
        foreach ($projects as $project) {
            try {
                if ($monitor->poll($project)->value === 'unhealthy') {
                    $unhealthy++;
                }
            } catch (Throwable) {
                // A single project's misconfiguration must never abort the whole sweep.
            }
        }

        $this->info("cporter:check-health — checked {$projects->count()} project(s); {$unhealthy} unhealthy.");

        return self::SUCCESS;
    }
}
