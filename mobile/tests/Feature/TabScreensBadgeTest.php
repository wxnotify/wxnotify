<?php

use App\NativeComponents\AlertsScreen;
use App\NativeComponents\MapScreen;
use App\NativeComponents\ProductsScreen;
use App\NativeComponents\SettingsScreen;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    PushNotifications::shouldReceive('clearBadge')->never();
    Http::fake([
        'wx.test/api/v1/alerts?*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null, 'unread_count' => 2]]),
        'wx.test/api/v1/active-alerts' => Http::response(['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]),
        'wx.test/api/v1/map' => Http::response(['message' => 'unavailable'], 500),
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
    ]);
});

function badgeClearRequests(): int
{
    return Http::recorded(fn (Request $r) => str_contains($r->url(), '/push-token/badge'))->count();
}

test('opening a tab screen other than Alerts leaves the icon badge alone', function (string $screen) {
    Native::test($screen);

    expect($this->appBadge->counts)->toBe([])
        ->and(badgeClearRequests())->toBe(0);
})->with([MapScreen::class, ProductsScreen::class, SettingsScreen::class]);

test('opening Alerts sets the badge only from the feed\'s unread count', function () {
    Native::test(AlertsScreen::class);

    expect($this->appBadge->counts)->toBe([2])
        ->and(badgeClearRequests())->toBe(0);
});

test('opening Alerts offline leaves the icon badge alone', function () {
    $this->network->connected = false;

    Native::test(AlertsScreen::class);

    expect($this->appBadge->counts)->toBe([])
        ->and(badgeClearRequests())->toBe(0);
});
