<?php

use App\Http\Controllers\Api\V1\ArchiveController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BackupPlanController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\KubernetesController;
use App\Http\Controllers\Api\V1\NotificationChannelController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RepositoryController;
use App\Http\Controllers\Api\V1\SettingsController;
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
        Route::post('repositories/{repository}/import', [RepositoryController::class, 'import']);

        // Sources
        Route::apiResource('sources', SourceController::class);
        Route::post('sources/quick-add', [SourceController::class, 'quickAdd']);
        Route::post('sources/{source}/test-connection', [SourceController::class, 'testConnection']);
        Route::patch('sources/{source}/toggle', [SourceController::class, 'toggle']);
        Route::patch('sources/{source}/retention', [SourceController::class, 'updateRetention']);
        Route::get('sources/{source}/policies', [SourceController::class, 'policies']);
        Route::get('sources/{source}/archives', [SourceController::class, 'archives']);

        // Backup Plans (Policies)
        Route::apiResource('plans', BackupPlanController::class);
        Route::post('plans/{plan}/backup', [BackupPlanController::class, 'backup']);
        Route::post('plans/{plan}/restore', [BackupPlanController::class, 'restore']);
        Route::post('plans/{plan}/prune', [BackupPlanController::class, 'prune']);
        Route::post('plans/{plan}/verify', [BackupPlanController::class, 'verify']);
        Route::patch('plans/{plan}/toggle', [BackupPlanController::class, 'toggle']);

        // Jobs
        Route::get('jobs', [JobController::class, 'index']);
        Route::get('jobs/{job}', [JobController::class, 'show']);
        Route::get('jobs/{job}/log', [JobController::class, 'log']);
        Route::post('jobs/{job}/cancel', [JobController::class, 'cancel']);

        // Archives
        Route::get('archives', [ArchiveController::class, 'index']);
        Route::get('archives/{archive}', [ArchiveController::class, 'show']);
        Route::patch('archives/{archive}', [ArchiveController::class, 'update']);
        Route::delete('archives/{archive}', [ArchiveController::class, 'destroy']);
        Route::patch('archives/{archive}/keep-forever', [ArchiveController::class, 'keepForever']);
        Route::post('archives/{archive}/restore', [ArchiveController::class, 'restore']);
        Route::get('archives/{archive}/download', [ArchiveController::class, 'download']);

        // Notifications
        Route::apiResource('notifications', NotificationChannelController::class);

        // Documents
        Route::apiResource('documents', DocumentController::class);
        Route::post('documents/{document}/test', [DocumentController::class, 'test']);

        // Profiles (reusable schedule & retention presets)
        Route::apiResource('profiles', ProfileController::class);

        // App Settings
        Route::get('settings', [SettingsController::class, 'index']);
        Route::put('settings', [SettingsController::class, 'update']);

        // Kubernetes Radar
        Route::prefix('kubernetes')->group(function () {
            // Cluster management
            Route::get('clusters', [KubernetesController::class, 'clusters']);
            Route::post('clusters', [KubernetesController::class, 'storeCluster']);
            Route::put('clusters/{cluster}', [KubernetesController::class, 'updateCluster']);
            Route::delete('clusters/{cluster}', [KubernetesController::class, 'destroyCluster']);

            // Cluster-scoped operations
            Route::post('clusters/{cluster}/test', [KubernetesController::class, 'test']);
            Route::post('clusters/{cluster}/discover', [KubernetesController::class, 'discover']);
            Route::get('clusters/{cluster}/namespaces', [KubernetesController::class, 'namespaces']);
            Route::post('clusters/{cluster}/import', [KubernetesController::class, 'import']);
            Route::post('clusters/{cluster}/ignore', [KubernetesController::class, 'ignore']);
            Route::get('clusters/{cluster}/ignored', [KubernetesController::class, 'ignored']);
            Route::delete('clusters/{cluster}/ignored/{radarIgnore}', [KubernetesController::class, 'unignore']);
            Route::post('clusters/{cluster}/list-databases', [KubernetesController::class, 'listDatabases']);
        });
    });
});
