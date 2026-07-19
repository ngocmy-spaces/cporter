<?php

use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DeploymentController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ReleaseController;
use App\Http\Controllers\Api\RollbackController;
use App\Http\Controllers\Api\SystemController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookController;
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
        // Public: primes the XSRF-TOKEN cookie for the SPA before any POST. (A 401 on
        // /auth/user short-circuits CSRF's cookie-add, so the SPA can't rely on it.)
        Route::get('/csrf', fn () => response()->noContent());

        Route::post('/auth/login', [AuthController::class, 'login']);

        Route::middleware('auth')->group(function () {
            Route::post('/auth/logout', [AuthController::class, 'logout']);
            Route::get('/auth/user', [AuthController::class, 'user']);

            // ── Reads (admin + viewer) ──
            Route::get('/system/capabilities', [SystemController::class, 'capabilities']);
            Route::get('/system/cron', [SystemController::class, 'cron']);
            Route::get('/api-keys', [ApiKeyController::class, 'index']);
            Route::get('/projects', [ProjectController::class, 'index']);
            Route::get('/projects/{project}', [ProjectController::class, 'show']);
            Route::get('/projects/{project}/deployments', [DeploymentController::class, 'index']);
            Route::get('/projects/{project}/releases', [ReleaseController::class, 'index']);
            Route::get('/projects/{project}/activity', [AuditController::class, 'project']);
            Route::post('/projects/{project}/disk-usage/recompute', [ProjectController::class, 'recomputeDiskUsage']);
            Route::get('/deployments', [DeploymentController::class, 'recent']);
            Route::get('/deployments/{deployment}', [DeploymentController::class, 'detail']);
            Route::get('/audit-logs', [AuditController::class, 'index']);

            // ── Writes (admin only) ──
            Route::middleware('role:admin')->group(function () {
                Route::post('/system/capabilities/refresh', [SystemController::class, 'refreshCapabilities']);
                Route::post('/api-keys', [ApiKeyController::class, 'store']);
                Route::delete('/api-keys/{apiKey}', [ApiKeyController::class, 'destroy']);
                Route::post('/projects', [ProjectController::class, 'store']);
                Route::post('/projects/{project}/preflight', [ProjectController::class, 'preflight']);
                Route::post('/projects/{project}/clone', [ProjectController::class, 'clone']);
                Route::patch('/projects/{project}', [ProjectController::class, 'update']);
                Route::delete('/projects/{project}', [ProjectController::class, 'destroy']);
                Route::post('/releases/{release}/activate', [ReleaseController::class, 'activate']);

                Route::get('/users', [UserController::class, 'index']);
                Route::post('/users', [UserController::class, 'store']);
                Route::delete('/users/{user}', [UserController::class, 'destroy']);
            });
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

        Route::middleware('scope:deploy')->group(function () {
            Route::post('/projects/{project}/deployments', [DeploymentController::class, 'store']);

            // Chunked upload for artifacts larger than post_max_size (docs/SPEC.md §6).
            Route::post('/projects/{project}/artifacts/uploads', [DeploymentController::class, 'uploadInit']);
            Route::put('/projects/{project}/artifacts/uploads/{uploadId}/chunks/{index}', [DeploymentController::class, 'uploadChunk'])
                ->whereNumber('index');
            Route::post('/projects/{project}/artifacts/uploads/{uploadId}/complete', [DeploymentController::class, 'uploadComplete']);
        });

        Route::middleware('scope:read')
            ->get('/projects/{project}/deployments/{deployment}', [DeploymentController::class, 'show']);

        Route::middleware('scope:rollback')
            ->post('/projects/{project}/rollback', [RollbackController::class, 'store']);
    });

    // ── CI webhooks (signature-verified, no session/token) ────────────────
    Route::post('/webhooks/{provider}', [WebhookController::class, 'handle'])
        ->whereIn('provider', ['github', 'gitlab']);
});
