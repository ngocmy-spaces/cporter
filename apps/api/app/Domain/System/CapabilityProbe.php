<?php

namespace App\Domain\System;

use Illuminate\Support\Collection;

/**
 * Probes the runtime for capabilities that gate cPorter features (docs/SPEC.md §2.1, §9.3).
 * Results drive the Admin "Settings" view and the deploy engine's driver selection.
 */
class CapabilityProbe
{
    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'open_basedir' => ((string) ini_get('open_basedir')) ?: null,
            'extensions' => $this->flags(['zip', 'curl', 'openssl', 'phar', 'mbstring', 'pdo_mysql'], 'extension_loaded'),
            'functions' => $this->functions(['proc_open', 'exec', 'symlink', 'readlink']),
            'symlink_runtime' => $this->testSymlink(),
            'limits' => [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ],
            'disk' => [
                'free' => @disk_free_space(base_path()) ?: null,
                'total' => @disk_total_space(base_path()) ?: null,
            ],
            'command_driver' => config('cporter.command_driver'),
            'cron_token_configured' => filled(config('cporter.cron_token')),
            'allowed_base_paths' => $this->basePaths(),
        ];
    }

    /**
     * @param  list<string>  $names
     * @return array<string, bool>
     */
    private function flags(array $names, callable $check): array
    {
        return (new Collection($names))->mapWithKeys(fn (string $n) => [$n => (bool) $check($n)])->all();
    }

    /**
     * A function is "available" only if it exists AND is not in disable_functions.
     *
     * @param  list<string>  $names
     * @return array<string, bool>
     */
    private function functions(array $names): array
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return (new Collection($names))
            ->mapWithKeys(fn (string $n) => [$n => function_exists($n) && ! in_array($n, $disabled, true)])
            ->all();
    }

    private function testSymlink(): bool
    {
        if (! function_exists('symlink')) {
            return false;
        }

        $dir = storage_path('app/private');
        @mkdir($dir, 0775, true);
        $target = $dir.'/.cap_target_'.uniqid();
        $link = $dir.'/.cap_link_'.uniqid();
        @file_put_contents($target, 'x');

        $ok = @symlink($target, $link);

        if ($ok) {
            @unlink($link);
        }
        @unlink($target);

        return (bool) $ok;
    }

    /**
     * @return list<array{path: string, exists: bool, writable: bool}>
     */
    private function basePaths(): array
    {
        /** @var list<string> $paths */
        $paths = config('cporter.allowed_base_paths', []);

        return (new Collection($paths))
            ->map(fn (string $p) => [
                'path' => $p,
                'exists' => is_dir($p),
                'writable' => is_writable($p),
            ])
            ->all();
    }
}
