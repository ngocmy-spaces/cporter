<?php

namespace App\Enums;

/**
 * Continuous health signal for a project (docs/SPEC.md §21.1). The single source of truth every
 * consumer (dashboard alerts, deploy-gate note, auto-rollback decision) reads instead of re-probing.
 */
enum ProjectHealth: string
{
    // The health_check_url last returned a 2xx.
    case Healthy = 'healthy';
    // The health_check_url last failed — the only state that raises an alert.
    case Unhealthy = 'unhealthy';
    // Never polled, or the project has no health_check_url to poll. Never alerts.
    case Unknown = 'unknown';
    // The project is disabled, so health is not monitored. Never alerts.
    case Paused = 'paused';
}
