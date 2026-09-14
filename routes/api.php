<?php

use App\Http\Controllers\IngestGlmFlashesController;
use App\Http\Controllers\IngestNwwsProductController;
use Illuminate\Support\Facades\Route;

Route::post('/pipeline/nwws-products', IngestNwwsProductController::class)
    ->middleware('pipeline.webhook');

Route::post('/pipeline/glm-flashes', IngestGlmFlashesController::class)
    ->middleware('pipeline.webhook');
