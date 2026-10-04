<?php

use App\Http\Controllers\Admin\ShapefileLayerGeoJsonController;
use App\Http\Controllers\AppAuthorizationController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    return redirect()->route('filament.dashboard.auth.login');
});

Route::middleware(['auth'])->get(
    '/admin/shapefile-layers/{shapefileLayer}/geojson',
    ShapefileLayerGeoJsonController::class,
)->name('admin.shapefile-layers.geojson');

Route::middleware(['auth', 'throttle:app-authorize'])->prefix('app-auth')->group(function () {
    Route::get('/authorize', [AppAuthorizationController::class, 'show'])->name('app-auth.authorize');
    Route::post('/authorize', [AppAuthorizationController::class, 'store'])->name('app-auth.authorize.store');
});
