<?php

use Illuminate\Support\Facades\Route;
use App\Http\Responses\ApiResponse;
use App\Http\Controllers\NotificationController;

/*
|--------------------------------------------------------------------------
| Notification Service — API Routes (v1)
|--------------------------------------------------------------------------
|
| Prefix: /api/v1
| All routes here are stateless and expect JSON.
|
*/

Route::get('/health', fn () => ApiResponse::success([
    'service'          => 'notification-service',
    'status'           => 'healthy',
    'timestamp'        => now()->toIso8601String(),
    'version'          => config('app.version', '1.0.0'),
    'environment'      => app()->environment(),
    'queue_connection' => config('queue.default'),
]));

Route::middleware('jwt.admin')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::get('/notifications/{uuid}', [NotificationController::class, 'show']);
    Route::post('/notifications/{uuid}/retry', [NotificationController::class, 'retry']);
});
