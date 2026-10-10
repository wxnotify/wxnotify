<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AlertRuleOptionsController;
use App\Http\Controllers\Api\V1\AppTokenController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LocationSharingController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NwsOfficeController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\ZoneController;
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
        Route::get('/alert-rules', [AlertRuleController::class, 'index'])->name('api.v1.alert-rules.index');
        Route::post('/alert-rules', [AlertRuleController::class, 'store'])->name('api.v1.alert-rules.store');
        Route::get('/alert-rules/{id}', [AlertRuleController::class, 'show'])->whereNumber('id')->name('api.v1.alert-rules.show');
        Route::put('/alert-rules/{id}', [AlertRuleController::class, 'update'])->whereNumber('id')->name('api.v1.alert-rules.update');
        Route::patch('/alert-rules/{id}', [AlertRuleController::class, 'setActive'])->whereNumber('id')->name('api.v1.alert-rules.set-active');
        Route::delete('/alert-rules/{id}', [AlertRuleController::class, 'destroy'])->whereNumber('id')->name('api.v1.alert-rules.destroy');
        Route::get('/alert-rule-options', AlertRuleOptionsController::class)->name('api.v1.alert-rule-options');
        Route::get('/nws-offices', NwsOfficeController::class)->name('api.v1.nws-offices');
        Route::get('/zones', ZoneController::class)->name('api.v1.zones');
        Route::put('/push-token', [PushTokenController::class, 'update'])->name('api.v1.push-token.update');
        Route::delete('/push-token', [PushTokenController::class, 'destroy'])->name('api.v1.push-token.destroy');
        Route::put('/location-sharing', [LocationSharingController::class, 'update'])->name('api.v1.location-sharing.update');
        Route::get('/location-sharing', [LocationSharingController::class, 'show'])->name('api.v1.location-sharing.show');
        Route::delete('/location-sharing', [LocationSharingController::class, 'destroy'])->name('api.v1.location-sharing.destroy');
        Route::delete('/auth/token', [AppTokenController::class, 'destroy'])->name('api.v1.auth.token.destroy');
    });

    Route::middleware(['auth:sanctum', 'ability:location', ExtendAppTokenExpiry::class, 'throttle:app-location'])->group(function (): void {
        Route::post('/location', [LocationController::class, 'store'])->name('api.v1.location.store');
    });
});
