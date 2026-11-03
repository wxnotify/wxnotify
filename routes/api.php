<?php

use App\Http\Controllers\Api\V1\ActiveAlertController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\AlertRuleController;
use App\Http\Controllers\Api\V1\AlertRuleOptionsController;
use App\Http\Controllers\Api\V1\AppTokenController;
use App\Http\Controllers\Api\V1\ChannelController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\LocationSharingController;
use App\Http\Controllers\Api\V1\MapController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NwsOfficeController;
use App\Http\Controllers\Api\V1\OutlookMapController;
use App\Http\Controllers\Api\V1\PlaceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\PushTokenController;
use App\Http\Controllers\Api\V1\SavedLocationController;
use App\Http\Controllers\Api\V1\WeatherController;
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

    Route::get('/outlook-maps/{batchKey}/{key}', [OutlookMapController::class, 'show'])
        ->where('key', 'day1|day2|day3|day48')
        ->middleware(['signed', 'throttle:app-outlook-map'])
        ->name('api.v1.outlook-maps.show');

    Route::middleware(['auth:sanctum', 'ability:app', ExtendAppTokenExpiry::class, 'throttle:app-api'])->group(function (): void {
        Route::get('/me', MeController::class)->name('api.v1.me');
        Route::get('/alerts', [AlertController::class, 'index'])->name('api.v1.alerts.index');
        Route::post('/alerts/read-all', [AlertController::class, 'readAll'])->name('api.v1.alerts.read-all');
        Route::post('/alerts/read-ref', [AlertController::class, 'readByRef'])->name('api.v1.alerts.read-ref');
        Route::get('/alerts/by-ref/{ref}', [AlertController::class, 'showByRef'])->name('api.v1.alerts.show-by-ref');
        Route::get('/alerts/{id}', [AlertController::class, 'show'])->whereNumber('id')->name('api.v1.alerts.show');
        Route::post('/alerts/{id}/read', [AlertController::class, 'read'])->whereNumber('id')->name('api.v1.alerts.read');
        Route::get('/alert-rules', [AlertRuleController::class, 'index'])->name('api.v1.alert-rules.index');
        Route::post('/alert-rules', [AlertRuleController::class, 'store'])->name('api.v1.alert-rules.store');
        Route::get('/alert-rules/{id}', [AlertRuleController::class, 'show'])->whereNumber('id')->name('api.v1.alert-rules.show');
        Route::put('/alert-rules/{id}', [AlertRuleController::class, 'update'])->whereNumber('id')->name('api.v1.alert-rules.update');
        Route::patch('/alert-rules/{id}', [AlertRuleController::class, 'setActive'])->whereNumber('id')->name('api.v1.alert-rules.set-active');
        Route::delete('/alert-rules/{id}', [AlertRuleController::class, 'destroy'])->whereNumber('id')->name('api.v1.alert-rules.destroy');
        Route::get('/locations', [SavedLocationController::class, 'index'])->name('api.v1.locations.index');
        Route::post('/locations', [SavedLocationController::class, 'store'])->name('api.v1.locations.store');
        Route::get('/locations/{id}', [SavedLocationController::class, 'show'])->whereNumber('id')->name('api.v1.locations.show');
        Route::put('/locations/{id}', [SavedLocationController::class, 'update'])->whereNumber('id')->name('api.v1.locations.update');
        Route::delete('/locations/{id}', [SavedLocationController::class, 'destroy'])->whereNumber('id')->name('api.v1.locations.destroy');
        Route::get('/channel-types', [ChannelController::class, 'types'])->name('api.v1.channel-types');
        Route::get('/channels', [ChannelController::class, 'index'])->name('api.v1.channels.index');
        Route::post('/channels', [ChannelController::class, 'store'])->middleware('throttle:app-channel-send')->name('api.v1.channels.store');
        Route::post('/channels/{id}/verify', [ChannelController::class, 'verify'])->whereNumber('id')->name('api.v1.channels.verify');
        Route::post('/channels/{id}/resend', [ChannelController::class, 'resend'])->whereNumber('id')->middleware('throttle:app-channel-send')->name('api.v1.channels.resend');
        Route::post('/channels/{id}/test', [ChannelController::class, 'test'])->whereNumber('id')->middleware('throttle:app-channel-send')->name('api.v1.channels.test');
        Route::put('/channels/{id}', [ChannelController::class, 'update'])->whereNumber('id')->name('api.v1.channels.update');
        Route::delete('/channels/{id}', [ChannelController::class, 'destroy'])->whereNumber('id')->name('api.v1.channels.destroy');
        Route::get('/map', MapController::class)->name('api.v1.map');
        Route::get('/active-alerts', ActiveAlertController::class)->name('api.v1.active-alerts');
        Route::get('/weather', WeatherController::class)->name('api.v1.weather');
        Route::get('/alert-rule-options', AlertRuleOptionsController::class)->name('api.v1.alert-rule-options');
        Route::get('/nws-offices', NwsOfficeController::class)->name('api.v1.nws-offices');
        Route::get('/products', [ProductController::class, 'index'])->name('api.v1.products.index');
        Route::get('/product-types', [ProductController::class, 'types'])->name('api.v1.product-types');
        Route::get('/zones', ZoneController::class)->name('api.v1.zones');
        Route::get('/places/search', [PlaceController::class, 'search'])->middleware('throttle:app-places')->name('api.v1.places.search');
        Route::get('/places/reverse', [PlaceController::class, 'reverse'])->middleware('throttle:app-places')->name('api.v1.places.reverse');
        Route::put('/push-token', [PushTokenController::class, 'update'])->name('api.v1.push-token.update');
        Route::delete('/push-token', [PushTokenController::class, 'destroy'])->name('api.v1.push-token.destroy');
        Route::post('/push-token/badge/clear', [PushTokenController::class, 'clearBadge'])->name('api.v1.push-token.badge.clear');
        Route::put('/location-sharing', [LocationSharingController::class, 'update'])->name('api.v1.location-sharing.update');
        Route::get('/location-sharing', [LocationSharingController::class, 'show'])->name('api.v1.location-sharing.show');
        Route::delete('/location-sharing', [LocationSharingController::class, 'destroy'])->name('api.v1.location-sharing.destroy');
        Route::delete('/auth/token', [AppTokenController::class, 'destroy'])->name('api.v1.auth.token.destroy');
    });

    Route::middleware(['auth:sanctum', 'ability:location', ExtendAppTokenExpiry::class, 'throttle:app-location'])->group(function (): void {
        Route::post('/location', [LocationController::class, 'store'])->name('api.v1.location.store');
    });
});
