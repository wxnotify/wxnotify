<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AppTokenController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\IngestEmwinProductController;
use App\Http\Controllers\IngestGlmFlashesController;
use App\Http\Controllers\IngestNwwsProductController;
use App\Http\Middleware\ExtendAppTokenExpiry;
use Illuminate\Support\Facades\Route;

Route::post('/pipeline/nwws-products', IngestNwwsProductController::class)
    ->middleware('pipeline.webhook');

Route::post('/pipeline/emwin-products', IngestEmwinProductController::class)
    ->middleware('pipeline.webhook');

Route::post('/pipeline/glm-flashes', IngestGlmFlashesController::class)
    ->middleware('pipeline.webhook');

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/token', [AppTokenController::class, 'store'])
        ->middleware('throttle:app-auth')
        ->name('api.v1.auth.token.store');

    Route::middleware(['auth:sanctum', 'ability:app', ExtendAppTokenExpiry::class, 'throttle:app-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::get('/alerts', [AlertController::class, 'index'])->name('api.v1.alerts.index');
        Route::get('/alerts/{id}', [AlertController::class, 'show'])->whereNumber('id')->name('api.v1.alerts.show');
        Route::put('/push-token', [PushTokenController::class, 'update'])->name('api.v1.push-token.update');
        Route::delete('/push-token', [PushTokenController::class, 'destroy'])->name('api.v1.push-token.destroy');
        Route::delete('/auth/token', [AppTokenController::class, 'destroy'])->name('api.v1.auth.token.destroy');
    });

    Route::middleware(['auth:sanctum', 'ability:location', ExtendAppTokenExpiry::class, 'throttle:app-location'])->group(function (): void {
        Route::post('/location', [LocationController::class, 'store'])->name('api.v1.location.store');
    });
});
