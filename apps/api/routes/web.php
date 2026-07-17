<?php

use Illuminate\Support\Facades\Route;

/*
| SPA fallback: serve the built admin panel (apps/web dist copied into public/).
| API routes live under /api (registered separately) and are not matched here.
*/
Route::get('/{any?}', function () {
    $index = public_path('index.html');

    return file_exists($index)
        ? response()->file($index)
        : response('cPorter API is running. Build the admin SPA with `pnpm build:web`.', 200);
})->where('any', '^(?!api|up).*$');
