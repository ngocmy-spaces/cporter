<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReleaseController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
| cPorter API v1 — see docs/SPEC.md §7 for the full endpoint list.
| Deploy/rollback endpoints land in Phase 1 (see TASKS.md).
*/
Route::prefix('v1')->group(function () {
    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'cporter',
            'time' => now()->toIso8601String(),
        ]);
    });

    // ── Admin (same-origin session, web guard) ────────────────────────────
    Route::middleware('web')->group(function () {
        Route::post('/auth/login', [AuthController::class, 'login']);

        Route::middleware('auth')->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/user', [AuthController::class, 'user']);

            Route::get('/system/capabilities', [SystemController::class, 'capabilities']);
            Route::post('/system/capabilities/refresh', [SystemController::class, 'refreshCapabilities']);

            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::post('/api-keys', [ApiKeyController::class, 'store']);
            Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);

            Route::get('/projects', [ProjectController::class, 'index']);
            Route::post('/projects', [ProjectController::class, 'store']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/projects/{project}/deployments', [DeploymentController::class, 'index']);
            Route::get('/projects/{project}/releases', [ReleaseController::class, 'index']);

            Route::get('/deployments', [DeploymentController::class, 'recent']);
            Route::get('/deployments/{deployment}', [DeploymentController::class, 'detail']);

            Route::post('/releases/{release}/activate', [ReleaseController::class, 'activate']);
        });
    });

    // ── CI-facing (API key) ───────────────────────────────────────────────
    Route::middleware('apikey')->group(function () {
        // Lets CI verify a token and see its scopes/project binding.
        Route::get('/whoami', function (Request $request) {
            $apiKey = $request->attributes->get('api_key');

            return response()->json(['data' => [
                'name' => $apiKey->name,
                'scopes' => $apiKey->scopes ?? [],
                'project_id' => $apiKey->project_id,
            ]]);
        });

        Route::middleware('scope:deploy')
            ->post('/projects/{project}/deployments', [DeploymentController::class, 'store']);

        Route::middleware('scope:read')
            ->get('/projects/{project}/deployments/{deployment}', [DeploymentController::class, 'show']);

        Route::middleware('scope:rollback')
            ->post('/projects/{project}/rollback', [RollbackController::class, 'store']);
    });
});
