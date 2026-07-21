<?php

namespace App\Domain\Deploy\PostDeploy;

use App\Domain\Deploy\ArtifactPruner;
use Throwable;

/**
 * Post-deploy cleanup: reclaim the project's now-redundant artifact .zip files, keeping only the
 * live release's zip (+ any in-flight deploy's). Recorded as the `prune_artifacts` pipeline step.
 *
 * The deploy has already succeeded, so a cleanup hiccup must never look like a failed deploy: a
 * failure is recorded as a single non-fatal `warning` step (not a red failure, and not rethrown).
 */
class PruneArtifactsTask implements PostDeployTask
{
    public function __construct(private readonly ArtifactPruner $pruner) {}

    public function name(): string
    {
        return 'prune_artifacts';
    }

    public function handle(PostDeployContext $ctx): void
    {
        try {
            $result = $this->pruner->prune($ctx->project);
        } catch (Throwable $e) {
            $ctx->steps->warn($this->name(), 'Artifact cleanup skipped: '.$e->getMessage());

            return;
        }

        // Prune succeeded — record a green step with a human summary (run() won't throw here).
        $ctx->steps->run($this->name(), fn (): string => $result['removed'] === 0
            ? 'No redundant artifacts to reclaim.'
            : sprintf('Reclaimed %d artifact archive(s), freed %s.', $result['removed'], $this->humanBytes($result['freed'])));
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$i];
    }
}
