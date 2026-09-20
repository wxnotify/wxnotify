<?php

use App\Http\Controllers\ViewProductController;
use Illuminate\Support\Facades\Route;

// Loaded outside the web middleware group on purpose: no session or cookies,
// so these public, immutable pages stay cacheable at the CDN.
Route::group(array_filter(['domain' => config('wxnotify.product_domain')]), function (): void {
    Route::get('/view-product/{slug}', ViewProductController::class)
        ->where('slug', '[a-z0-9-]+')
        ->name('view-product.show');
});
