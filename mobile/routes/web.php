<?php

use App\Support\LocalCache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The Map tab's page for its web view (PHP mode). It holds no token; it is the cached page for the last snapshot.
Route::get('/map-page', function (LocalCache $cache) {
    $page = $cache->mapPage();

    abort_if($page === null, 404);

    return response($page)->header('Content-Type', 'text/html; charset=utf-8')->header('Cache-Control', 'no-store');
})->name('map.page');
