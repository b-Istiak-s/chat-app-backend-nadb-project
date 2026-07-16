<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Chat\ChatController;
use App\Http\Controllers\Webhook\BdAppsNotifyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Layered architecture: Route → Middleware → Validation (FormRequest) →
| Controller → Service → Repository → Model. All responses use the
| JsonResponseTrait envelope. Auth via Laravel Sanctum (no role
| middleware — single user type).
|
*/

// Public auth (no Sanctum).
Route::prefix('auth')->group(function () {
    Route::post('/start', [AuthController::class, 'start']);
    Route::post('/verify', [AuthController::class, 'verify']);
});

// BDApps webhook (uses shared notify_secret, not Sanctum).
Route::post('/webhooks/bdapps/notify', [BdAppsNotifyController::class, 'handle']);

// Protected routes.
Route::middleware('auth:sanctum')->group(function () {
    // Auth.
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/unsubscribe', [AuthController::class, 'unsubscribe']);
    });

    // Chat (SSE).
    Route::prefix('chat')->group(function () {
        Route::post('/messages', [ChatController::class, 'stream']);
        Route::get('/messages', [ChatController::class, 'history']);
    });
});

Route::get('/user', fn (Request $request) => $request->user());