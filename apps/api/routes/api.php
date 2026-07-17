<?php

use Illuminate\Support\Facades\Route;

/*
| cPorter API v1 — see docs/SPEC.md §7 for the full endpoint list.
| Endpoints below are stubs; controllers land per-task (see TASKS.md).
*/
Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'cporter',
            'time' => now()->toIso8601String(),
        ]);
    });

    // TODO (Phase 1/2):
    // Route::middleware('auth:sanctum')->group(function () {
    //     Route::get('/projects', [ProjectController::class, 'index']);
    //     Route::post('/projects/{slug}/deployments', [DeploymentController::class, 'store']);
    //     Route::post('/projects/{slug}/rollback', [RollbackController::class, 'store']);
    //     ...
    // });
});
