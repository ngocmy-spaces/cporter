<?php

namespace App\Domain\System;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * Visibility for artifact-store cleanup (docs/SPEC.md §6, §11). `disk_usage` measures each
 * project's base_path — NOT cPorter's own artifact store (storage/app/artifacts), the thing
 * retention reclaims. Each housekeep sweep records a heartbeat here; the Admin UI reads status()
 * to see the store size, backlog, and whether cleanup is actually running — without any external
 * monitoring service. Mirrors {@see CronHeartbeat}.
 */
class ArtifactStorageHeartbeat
{
    public const KEY = 'artifact_storage_last_sweep';

    // A sweep older than this reads as stale (the housekeeper runs ~every 5 min; generous
    // multiple so a slow/skipped tick doesn't flap the status).
    private const STALE_AFTER = 1800; // 30 min

    /**
     * @param  array{projects_swept:int, reclaimed_count:int, freed_bytes:int, store_bytes:int, unpruned_count:int}  $stats
     */
    public function record(array $stats): void
    {
        Setting::write(self::KEY, array_merge($stats, [
            'at' => now()->toIso8601String(),
            'host' => gethostname() ?: null,
            'prune_enabled' => (bool) config('cporter.artifact.prune_after_deploy', true),
        ]));
    }

    /**
     * @return array{state:string, last_run_at:?string, age_seconds:?int, store_bytes:?int, unpruned_count:?int, reclaimed_count:?int, freed_bytes:?int, projects_swept:?int, prune_enabled:bool, warn_bytes:int, host:?string, warnings:list<string>}
     */
    public function status(): array
    {
        $pruneEnabled = (bool) config('cporter.artifact.prune_after_deploy', true);
        $warnBytes = (int) config('cporter.artifact.store_warn_bytes', 0);
        $last = Setting::read(self::KEY);

        if (! is_array($last) || ! isset($last['at'])) {
            // Never swept yet (fresh install) — still surface whether pruning is on.
            return [
                'state' => 'unknown',
                'last_run_at' => null,
                'age_seconds' => null,
                'store_bytes' => null,
                'unpruned_count' => null,
                'reclaimed_count' => null,
                'freed_bytes' => null,
                'projects_swept' => null,
                'prune_enabled' => $pruneEnabled,
                'warn_bytes' => $warnBytes,
                'host' => null,
                'warnings' => $pruneEnabled ? [] : ['pruning_disabled'],
            ];
        }

        $age = max(0, now()->getTimestamp() - Carbon::parse($last['at'])->getTimestamp());
        $storeBytes = (int) ($last['store_bytes'] ?? 0);

        // Trust the persisted flag from the last sweep, falling back to current config.
        $prune = (bool) ($last['prune_enabled'] ?? $pruneEnabled);

        $warnings = [];
        if (! $prune) {
            $warnings[] = 'pruning_disabled';
        }
        if ($warnBytes > 0 && $storeBytes > $warnBytes) {
            $warnings[] = 'store_over_threshold';
        }
        if ($age > self::STALE_AFTER) {
            $warnings[] = 'sweep_stale';
        }

        return [
            'state' => $warnings === [] ? 'healthy' : 'warning', // healthy | warning | unknown
            'last_run_at' => $last['at'],
            'age_seconds' => $age,
            'store_bytes' => $storeBytes,
            'unpruned_count' => (int) ($last['unpruned_count'] ?? 0),
            'reclaimed_count' => (int) ($last['reclaimed_count'] ?? 0),
            'freed_bytes' => (int) ($last['freed_bytes'] ?? 0),
            'projects_swept' => (int) ($last['projects_swept'] ?? 0),
            'prune_enabled' => $prune,
            'warn_bytes' => $warnBytes,
            'host' => $last['host'] ?? null,
            'warnings' => $warnings,
        ];
    }
}
