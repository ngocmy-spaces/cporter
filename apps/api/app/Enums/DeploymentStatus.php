<?php

namespace App\Enums;

/**
 * Status of a deployment pipeline run (docs/SPEC.md §5, §6).
 * `HooksPending` = code activated, waiting on cron-worker to run Laravel hooks (§9).
 */
enum DeploymentStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case HooksPending = 'hooks_pending';
    case Success = 'success';
    case Failed = 'failed';
    case RolledBack = 'rolled_back';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Success, self::Failed, self::RolledBack => true,
            default => false,
        };
    }
}
