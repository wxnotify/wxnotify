<?php

use App\NativeComponents\AccountScreen;
use App\NativeComponents\AlertDetail;
use App\NativeComponents\AlertsScreen;
use App\NativeComponents\AuthCallback;
use App\NativeComponents\AuthDenied;
use App\NativeComponents\LocationEditor;
use App\NativeComponents\LocationPrompt;
use App\NativeComponents\LocationsScreen;
use App\NativeComponents\PushPrompt;
use App\NativeComponents\RuleEditor;
use App\NativeComponents\RulesScreen;
use App\NativeComponents\SignInScreen;
use Illuminate\Support\Facades\Route;

Route::native('/', AlertsScreen::class);
Route::native('/sign-in', SignInScreen::class);
Route::native('/auth/callback/{code}/{state}', AuthCallback::class);
Route::native('/auth/denied/{state}', AuthDenied::class);
Route::native('/alerts/{id}', AlertDetail::class);
Route::native('/rules', RulesScreen::class);
Route::native('/rules/new', RuleEditor::class);
Route::native('/rules/{id}', RuleEditor::class);
Route::native('/locations', LocationsScreen::class);
Route::native('/locations/new', LocationEditor::class);
Route::native('/locations/{id}', LocationEditor::class);
Route::native('/account', AccountScreen::class);
Route::native('/enable-push', PushPrompt::class);
Route::native('/share-location', LocationPrompt::class);
