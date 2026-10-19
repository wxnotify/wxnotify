<?php

use App\NativeComponents\AccountScreen;
use App\NativeComponents\AlertsScreen;
use App\NativeComponents\WelcomeFlow;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Events\PushNotification\TokenGenerated;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    System::shouldReceive('isIos')->andReturn(false)->byDefault();
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted')->byDefault();
    Http::fake([
        'wx.test/api/v1/push-token' => Http::response(null, 204),
        'wx.test/api/v1/alert-rule-options' => Http::response(['data' => ['can_share_phone_location' => false]]),
        'wx.test/api/v1/me' => Http::response(['data' => ['id' => 5, 'name' => 'Pat', 'email' => 'pat@example.com', 'tier' => ['value' => 'plus', 'label' => 'Plus'], 'unit_system' => 'imperial', 'timezone' => 'America/Chicago']]),
        'wx.test/api/v1/alerts*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]]),
        'wx.test/api/v1/active-alerts' => Http::response(['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]),
    ]);
});

test('the mounted screen registers a token the plugin hands over while push is on', function (string $screen) {
    (new PushState)->markEnabled();
    PushNotifications::shouldReceive('enroll')->byDefault();

    Native::test($screen)->emitNative(TokenGenerated::class, ['token' => 'fcm-1', 'id' => null]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
        && $r->url() === 'https://wx.test/api/v1/push-token'
        && $r['token'] === 'fcm-1'
        && $r['platform'] === 'android');
})->with([AlertsScreen::class, AccountScreen::class, WelcomeFlow::class]);

test('the mounted screen sends nothing for a token while push is off', function (string $screen) {
    Native::test($screen)->emitNative(TokenGenerated::class, ['token' => 'fcm-1', 'id' => null]);

    Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
})->with([AlertsScreen::class, AccountScreen::class, WelcomeFlow::class]);
