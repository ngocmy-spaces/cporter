<?php

return [

    'default' => env('QUEUE_CONNECTION', 'database'),

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Must exceed DeployProjectJob::$timeout (600s) — otherwise a long extract lets the queue
            // re-reserve the same job and a second worker re-runs a deploy already in progress
            // (double-run corruption; the file lock loser flips the row to failed). Safe for the
            // multi-worker deploy model (docs/SPEC.md §6, §10).
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 660),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Same constraint as the database driver: keep above DeployProjectJob::$timeout (600s).
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 660),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'job_batches',
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => 'failed_jobs',
    ],

];
