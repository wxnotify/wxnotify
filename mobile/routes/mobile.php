<?php

use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\SignInScreen;
use Illuminate\Support\Facades\Route;

Route::native('/sign-in', SignInScreen::class);
Route::native('/auth/callback/{code}/{state}', AuthCallback::class);
Route::native('/auth/denied/{state}', AuthDenied::class);
