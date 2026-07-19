<?php

namespace App\Domain\Deploy;

/**
 * Renders a project's env vars into a `.env` file body cPorter writes to shared/.env on deploy
 * (docs/SPEC.md §9). The first line is the {@see self::MARKER} ownership header: cPorter only
 * overwrites a shared/.env that carries this marker, so a hand-created file is never clobbered
 * (see CpanelFilesystemAdapter::writeSharedFile).
 *
 * Values are always double-quoted and escaped (phpdotenv-safe), so spaces, `#`, `=` and newlines
 * inside a value survive. Output is deterministic — identical input yields byte-identical output.
 */
class EnvFileRenderer
{
    /** First line of any cPorter-managed .env; also the ownership sentinel checked before overwrite. */
    public const MARKER = '# Managed by cPorter — do not edit; overwritten on each deploy.';

    /**
     * @param  list<array{key: string, value: string}>  $envVars
     */
    public static function render(array $envVars): string
    {
        $lines = [self::MARKER];

        foreach ($envVars as $var) {
            $key = (string) ($var['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $lines[] = $key.'="'.self::escape((string) ($var['value'] ?? '')).'"';
        }

        return implode("\n", $lines)."\n";
    }

    /** Escape a value for a double-quoted .env entry. Order matters: backslash first. */
    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', '"', "\n", "\r", "\t"],
            ['\\\\', '\\"', '\\n', '\\r', '\\t'],
            $value,
        );
    }
}
