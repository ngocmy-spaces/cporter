<?php

namespace App\Domain\Deploy;

use App\Enums\ProjectHealth;
use App\Enums\ProjectStatus;
use App\Models\Project;

/**
 * Continuous project-health monitoring (docs/SPEC.md §21.1).
 *
 * The single writer of `projects.health_status` — both the scheduled `cporter:check-health`
 * command and the deploy-time activation gate route through here so every consumer reads one
 * persisted signal instead of re-probing. A project without a health_check_url is `unknown`;
 * a disabled project is `paused`; neither raises an alert.
 */
class ProjectHealthMonitor
{
    public function __construct(private readonly HealthChecker $health) {}

    /**
     * Evaluate one project's current health and persist the result. Disabled → paused,
     * no URL → unknown, otherwise a single-shot poll (no long retry loop — this runs across
     * every project on a schedule) decides healthy/unhealthy.
     */
    public function poll(Project $project): ProjectHealth
    {
        if ($project->status === ProjectStatus::Disabled) {
            return $this->set($project, ProjectHealth::Paused);
        }

        if (! filled($project->health_check_url)) {
            return $this->set($project, ProjectHealth::Unknown);
        }

        $ok = $this->health->check(
            (string) $project->health_check_url,
            (int) config('cporter.health_check.monitor_timeout', 0),
        );

        return $this->set($project, $ok ? ProjectHealth::Healthy : ProjectHealth::Unhealthy);
    }

    /** Persist a health result already determined by the caller (e.g. the deploy-time gate). */
    public function set(Project $project, ProjectHealth $status): ProjectHealth
    {
        $now = now();

        $project->forceFill([
            'health_status' => $status,
            'health_checked_at' => $now,
            'health_last_ok_at' => $status === ProjectHealth::Healthy ? $now : $project->health_last_ok_at,
        ])->save();

        return $status;
    }
}
