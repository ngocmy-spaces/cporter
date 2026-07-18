<?php

namespace App\Providers;

use App\Adapters\Storage\CpanelFilesystemAdapter;
use App\Adapters\Storage\StorageAdapter;
use App\Domain\Storage\PathJail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The filesystem jail is derived from config and shared app-wide (docs/SPEC.md §11, §12).
        $this->app->singleton(PathJail::class, fn () => PathJail::fromConfig());

        // Storage abstraction → cPanel filesystem adapter (docs/SPEC.md §11).
        $this->app->singleton(StorageAdapter::class, fn ($app) => new CpanelFilesystemAdapter(
            $app->make(PathJail::class),
            (int) config('cporter.artifact.max_files', 50000),
            (int) config('cporter.artifact.max_bytes', 256 * 1024 * 1024),
            (int) config('cporter.artifact.max_uncompressed_bytes', 1024 * 1024 * 1024),
            (int) config('cporter.lock_ttl', 900),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Surface misconfigured jail roots early — a relative base path would silently
        // never match, disabling deploys for that project (docs/SPEC.md §12).
        foreach ((array) config('cporter.allowed_base_paths', []) as $path) {
            if (is_string($path) && $path !== '' && ! str_starts_with($path, '/')) {
                Log::warning("cPorter: CPORTER_ALLOWED_BASE_PATHS entry is not absolute and will be ignored: {$path}");
            }
        }
    }
}
