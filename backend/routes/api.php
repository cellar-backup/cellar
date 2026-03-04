<?php

use App\Http\Controllers\Api\V1\ArchiveController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupPlanController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Http\Controllers\Api\V1\RepositoryController;
use App\Http\Controllers\Api\V1\SourceController;
use App\Http\Controllers\Api\V1\SystemController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Cellar API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| Auth routes are public; everything else requires Sanctum auth.
|
*/

// ── Public ──────────────────────────────────────────────────────────────

Route::prefix('v1')->group(function () {

    Route::post('auth/login', [AuthController::class, 'login']);
    Route::get('system/health', [SystemController::class, 'health']);

    // ── Authenticated ───────────────────────────────────────────────────

    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        // Repositories
        Route::apiResource('repositories', RepositoryController::class);
        Route::post('repositories/{repository}/test', [RepositoryController::class, 'test']);

        // Sources
        Route::apiResource('sources', SourceController::class);
        Route::post('sources/quick-add', [SourceController::class, 'quickAdd']);
        Route::post('sources/{source}/test-connection', [SourceController::class, 'testConnection']);

        // Backup Plans
        Route::apiResource('plans', BackupPlanController::class);
        Route::post('plans/{plan}/backup', [BackupPlanController::class, 'backup']);
        Route::post('plans/{plan}/restore', [BackupPlanController::class, 'restore']);
        Route::post('plans/{plan}/prune', [BackupPlanController::class, 'prune']);
        Route::post('plans/{plan}/verify', [BackupPlanController::class, 'verify']);

        // Jobs (read-only)
        Route::get('jobs', [JobController::class, 'index']);
        Route::get('jobs/{job}', [JobController::class, 'show']);

        // Archives
        Route::get('archives', [ArchiveController::class, 'index']);
        Route::get('archives/{archive}', [ArchiveController::class, 'show']);
        Route::delete('archives/{archive}', [ArchiveController::class, 'destroy']);
        Route::post('archives/{archive}/restore', [ArchiveController::class, 'restore']);
        Route::get('archives/{archive}/download', [ArchiveController::class, 'download']);

        // Notifications
        Route::apiResource('notifications', NotificationChannelController::class);

        // Documents
        Route::apiResource('documents', DocumentController::class);
        Route::post('documents/{document}/test', [DocumentController::class, 'test']);
    });
});
