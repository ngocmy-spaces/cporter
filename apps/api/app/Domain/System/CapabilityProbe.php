<?php

namespace App\Domain\System;

use App\Adapters\Command\CommandRunner;
use Illuminate\Support\Collection;

/**
 * Probes the runtime for capabilities that gate cPorter features (docs/SPEC.md §2.1, §9.3).
 * Results drive the Admin "Settings" view and the deploy engine's driver selection.
 */
class CapabilityProbe
{
    /** External CLIs a deploy hook might invoke (docs/SPEC.md §9). */
    public const BINARIES = ['php', 'composer', 'node', 'npm', 'python3'];

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
            'binaries' => $this->binaries(),
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

    /**
     * Fallback detection of hook CLIs from *web PHP* (docs/SPEC.md §9): web has no proc_open, so
     * instead of `command -v` we scan $PATH (plus common cPanel bin dirs) for an executable of each
     * name. The cron shell that actually runs hooks may resolve a different PATH, so this is only a
     * hint — the authoritative result comes from {@see binariesViaShell()}, run by the cron-worker.
     *
     * @return array<string, string|null> binary name => resolved absolute path (null if not found)
     */
    private function binaries(): array
    {
        $path = array_filter(explode(PATH_SEPARATOR, (string) getenv('PATH')));
        $dirs = array_values(array_unique([
            ...$path,
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/opt/cpanel/composer/bin',
        ]));

        $out = [];
        foreach (self::BINARIES as $name) {
            $out[$name] = null;
            foreach ($dirs as $dir) {
                $candidate = rtrim($dir, '/').'/'.$name;
                if (@is_file($candidate) && @is_executable($candidate)) {
                    $out[$name] = $candidate;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Authoritative detection of hook CLIs from the *cron shell* (docs/SPEC.md §9), where hooks
     * actually run. Uses `command -v <name>` — the same PATH resolution the hook itself sees — so a
     * hit here is a real guarantee, not a filesystem guess. Callable only where proc_open works
     * (the cron-worker); the caller must check {@see CommandRunner::isAvailable()} first.
     *
     * @return array<string, string|null> binary name => resolved path (null if not on the shell PATH)
     */
    public function binariesViaShell(CommandRunner $runner): array
    {
        $out = [];
        foreach (self::BINARIES as $name) {
            $result = $runner->run('command -v '.escapeshellarg($name), base_path(), [], 10);
            $path = trim($result->output);
            $out[$name] = ($result->ok() && $path !== '') ? $path : null;
        }

        return $out;
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
