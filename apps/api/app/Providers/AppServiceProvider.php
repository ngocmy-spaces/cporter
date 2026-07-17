<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
