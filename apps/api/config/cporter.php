<?php

use App\Domain\Deploy\PostDeploy\PruneArtifactsTask;

/*
| cPorter core configuration (docs/SPEC.md §9, §11, §12, §16).
| Runtime capabilities are probed and stored separately (Settings); this file holds
| static defaults and the filesystem jail.
*/

return [

    // Absolute base paths cPorter is allowed to touch (the jail). Every managed project's
    // base_path must be inside one of these. open_basedir is OFF on cPanel, so this is the
    // ONLY thing preventing path traversal — keep it tight (docs/SPEC.md §2.1, §12).
    'allowed_base_paths' => array_filter(
        explode(',', (string) env('CPORTER_ALLOWED_BASE_PATHS', ''))
    ),

    // Default number of releases to keep per project before pruning.
    'keep_releases' => (int) env('CPORTER_KEEP_RELEASES', 5),

    // Deploy lock time-to-live (seconds) before a stale lock may be force-cleared.
    'lock_ttl' => (int) env('CPORTER_LOCK_TTL', 900),

    // A deployment stuck in running/hooks_pending longer than this (seconds) is failed by
    // the housekeeper and its project lock released.
    'deployment_timeout' => (int) env('CPORTER_DEPLOYMENT_TIMEOUT', 1800),

    // Command execution driver: 'cron-worker' | 'manual' | 'ssh' | 'proc_open'.
    'command_driver' => env('CPORTER_COMMAND_DRIVER', 'cron-worker'),

    // Shared secret the cPanel cron uses to authenticate the scheduler tick / job runner.
    'cron_token' => env('CPORTER_CRON_TOKEN'),

    // Secret for verifying inbound CI webhooks (GitHub HMAC / GitLab token) — docs/SPEC.md §12.
    'webhook_secret' => env('CPORTER_WEBHOOK_SECRET'),

    // Artifact limits (defensive; host post_max_size is 256MB — docs/SPEC.md §2.1).
    'artifact' => [
        'max_bytes' => (int) env('CPORTER_ARTIFACT_MAX_BYTES', 256 * 1024 * 1024),
        'max_files' => (int) env('CPORTER_ARTIFACT_MAX_FILES', 50000),
        // Zip-bomb guard: reject archives whose total uncompressed size exceeds this.
        'max_uncompressed_bytes' => (int) env('CPORTER_ARTIFACT_MAX_UNCOMPRESSED_BYTES', 1024 * 1024 * 1024),
        // Reclaim an artifact's .zip once its deploy is stable (the zip is never re-read —
        // rollback re-points `current` at an existing release dir). DB rows are always kept.
        // Set false to retain every zip on disk.
        'prune_after_deploy' => filter_var(env('CPORTER_PRUNE_ARTIFACTS', true), FILTER_VALIDATE_BOOL),
        // Warn (in the System status) when the on-disk artifact store grows past this. 0 disables.
        'store_warn_bytes' => (int) env('CPORTER_ARTIFACT_STORE_WARN_BYTES', 2 * 1024 * 1024 * 1024),
    ],

    // Ordered tasks run after a successful deploy (docs/SPEC.md §6). Each is resolved from the
    // container and isolated — a failing task is recorded but never fails the deploy. Add
    // notification/webhook tasks here as they ship.
    'post_deploy_tasks' => [
        PruneArtifactsTask::class,
    ],

    // Health check defaults.
    'health_check' => [
        'timeout' => (int) env('CPORTER_HEALTHCHECK_TIMEOUT', 30),
        'expect_status' => 200,
    ],

];
