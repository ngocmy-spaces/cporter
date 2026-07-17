<?php

namespace App\Providers;

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

        // Bind Core Engine contracts to concrete adapters here (see docs/SPEC.md §11, §9).
        // $this->app->bind(
        //     \App\Adapters\Storage\StorageAdapter::class,
        //     \App\Adapters\Storage\CpanelFilesystemAdapter::class,
        // );
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
