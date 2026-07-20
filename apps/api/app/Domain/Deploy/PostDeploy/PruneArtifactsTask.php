<?php

namespace App\Domain\Deploy\PostDeploy;

use App\Domain\Deploy\ArtifactPruner;

/**
 * Post-deploy cleanup: reclaim the project's now-redundant artifact .zip files, keeping only the
 * live release's zip (+ any in-flight deploy's). Recorded as the `prune_artifacts` pipeline step.
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
        $ctx->steps->run($this->name(), function () use ($ctx): ?string {
            $result = $this->pruner->prune($ctx->project);

            if ($result['removed'] === 0) {
                return 'No redundant artifacts to reclaim.';
            }

            return sprintf(
                'Reclaimed %d artifact archive(s), freed %s.',
                $result['removed'],
                $this->humanBytes($result['freed']),
            );
        });
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
