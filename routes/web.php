<?php

use App\Http\Controllers\Admin\ShapefileLayerGeoJsonController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // return view('welcome');
    return redirect()->route('filament.dashboard.auth.login');
});

Route::middleware(['auth'])->get(
    '/admin/shapefile-layers/{shapefileLayer}/geojson',
    ShapefileLayerGeoJsonController::class,
)->name('admin.shapefile-layers.geojson');
