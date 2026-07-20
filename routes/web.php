<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\WebAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| All subscription management from the browser lives here. Auth uses
| Laravel's `web` guard (cookie session), independent of the Sanctum
| bearer tokens used by the mobile app — a web session does not mint
| an API token, and vice-versa.
|
*/

Route::get('/', fn () => view('landing'))->name('landing');

// Public auth.
Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
Route::post('/login/start', [WebAuthController::class, 'start'])->name('login.start');
Route::post('/login/verify', [WebAuthController::class, 'verify'])->name('login.verify');
Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');

// Authenticated dashboard.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::post('/dashboard/subscribe', [DashboardController::class, 'subscribe'])->name('dashboard.subscribe');
    Route::post('/dashboard/verify', [DashboardController::class, 'verify'])->name('dashboard.verify');
    Route::post('/dashboard/unsubscribe', [DashboardController::class, 'unsubscribe'])->name('dashboard.unsubscribe');

    // "Refresh status now" — polls BDApps /getStatus once and
    // applies the result, for users who don't want to wait for
    // the 10s post-verify job.
    Route::post('/dashboard/refresh', [DashboardController::class, 'refreshStatus'])
        ->name('dashboard.refresh');

    // APK download — only resolvable when the user holds an
    // active subscription. The artefact is not served as a
    // public asset; the only way to fetch it is via this
    // route, which returns 403 to unsubscribed users.
    Route::get('/downloads/app.apk', [DashboardController::class, 'downloadApk'])
        ->name('downloads.apk');
});
