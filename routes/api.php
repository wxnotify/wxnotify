<?php

use App\Http\Controllers\IngestNwwsProductController;
use Illuminate\Support\Facades\Route;

Route::post('/pipeline/nwws-products', IngestNwwsProductController::class)
    ->middleware('pipeline.webhook');
