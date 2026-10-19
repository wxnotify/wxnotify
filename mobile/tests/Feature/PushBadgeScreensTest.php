<?php

use App\Events\ForegroundPushReceived;
use App\Listeners\MarkForegroundPush;
use App\NativeComponents\AlertsScreen;
use App\NativeComponents\MapScreen;
use App\NativeComponents\ProductsScreen;
use App\NativeComponents\SettingsScreen;
use App\Services\PushBadge;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    // These tests drive the real badge service, not the suite's recording stand-in.
    app()->forgetInstance(PushBadge::class);
    (new TokenStore)->put('9|secret');
});

/**
 * Fakes the badge reset with the given status and what each tab screen loads on mount.
 */
function fakeTabScreenRequests(int $badgeResetStatus = 204): void
{
    Http::fake([
        'wx.test/api/v1/push-token/badge/clear' => Http::response(null, $badgeResetStatus),
        'wx.test/api/v1/alerts*' => Http::response(['data' => [], 'meta' => ['next_cursor' => null]]),
        'wx.test/api/v1/active-alerts' => Http::response(['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]),
        'wx.test/api/v1/map' => Http::response(['message' => 'unavailable'], 500),
        'wx.test/api/v1/product-types' => Http::response(['data' => []]),
        'wx.test/api/v1/nws-offices' => Http::response(['data' => []]),
    ]);
}

function badgeResetsSentFromScreens(): int
{
    return Http::recorded(fn (Request $r) => $r->method() === 'POST'
        && $r->url() === 'https://wx.test/api/v1/push-token/badge/clear')->count();
}

$tabScreens = [AlertsScreen::class, MapScreen::class, ProductsScreen::class, SettingsScreen::class];

test('mounting a tab screen clears the badge and resets the server count', function (string $screen) {
    PushNotifications::shouldReceive('clearBadge')->once();
    fakeTabScreenRequests();

    Native::test($screen);

    expect(badgeResetsSentFromScreens())->toBe(1);
})->with($tabScreens);

test('an ordinary poll tick neither calls the plugin nor the server', function (string $screen) {
    $this->freezeTime();
    PushNotifications::shouldReceive('clearBadge')->once();
    fakeTabScreenRequests();
    $component = Native::test($screen);

    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(1);
})->with($tabScreens);

test('a tick long after the previous one clears the badge and resets the server count', function (string $screen) {
    $this->freezeTime();
    PushNotifications::shouldReceive('clearBadge')->twice();
    fakeTabScreenRequests();
    $component = Native::test($screen);

    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');
    $this->travel(16)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(2);
})->with($tabScreens);

test('a tick exactly three intervals after the previous one is still ordinary', function () {
    $this->freezeSecond();
    PushNotifications::shouldReceive('clearBadge')->once();
    fakeTabScreenRequests();
    $component = Native::test(SettingsScreen::class);

    $this->travel(15)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(1);
});

test('a pending reset is retried on the next tick without calling the plugin', function (string $screen) {
    $this->freezeTime();
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake(['wx.test/api/v1/push-token/badge/clear' => Http::sequence()->push(null, 500)->push(null, 204)]);
    fakeTabScreenRequests();
    $component = Native::test($screen);

    expect((new PushState)->resetPending())->toBeTrue();

    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(2)
        ->and((new PushState)->resetPending())->toBeFalse();
})->with($tabScreens);

test('a failing server never throws into the screen', function (string $screen) {
    PushNotifications::shouldReceive('clearBadge')->once()->andThrow(new RuntimeException('plugin down'));
    fakeTabScreenRequests(badgeResetStatus: 500);

    Native::test($screen)
        ->firePoll('pollPushBadge')
        ->assertDontSee('plugin down');

    expect((new PushState)->resetPending())->toBeTrue();
})->with($tabScreens);

test('on iOS a foreground push flagged by the listener is cleared by the next tick, once', function (string $screen) {
    $this->freezeTime();
    System::shouldReceive('isIos')->andReturn(true);
    System::shouldReceive('isDarkMode')->andReturn(false);
    PushNotifications::shouldReceive('clearBadge')->twice();
    fakeTabScreenRequests();
    $component = Native::test($screen);

    app(MarkForegroundPush::class)->handle(new ForegroundPushReceived);
    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');
    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(2)
        ->and((new PushState)->pullForegroundPush())->toBeFalse();
})->with($tabScreens);

test('the dispatched foreground-push event reaches the listener and the next tick clears on iOS', function () {
    $this->freezeTime();
    System::shouldReceive('isIos')->andReturn(true);
    System::shouldReceive('isDarkMode')->andReturn(false);
    PushNotifications::shouldReceive('clearBadge')->twice();
    fakeTabScreenRequests();
    $component = Native::test(AlertsScreen::class);

    event(new ForegroundPushReceived);
    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(2);
});

test('on Android a flagged foreground push is consumed and nothing is cleared', function (string $screen) {
    $this->freezeTime();
    System::shouldReceive('isIos')->andReturn(false);
    System::shouldReceive('isDarkMode')->andReturn(false);
    PushNotifications::shouldReceive('clearBadge')->once();
    fakeTabScreenRequests();
    $component = Native::test($screen);

    app(MarkForegroundPush::class)->handle(new ForegroundPushReceived);
    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge');

    expect(badgeResetsSentFromScreens())->toBe(1)
        ->and((new PushState)->pullForegroundPush())->toBeFalse();
})->with($tabScreens);

test('a flagged foreground push with a failing plugin and server never throws into the screen', function () {
    $this->freezeTime();
    System::shouldReceive('isIos')->andReturn(true);
    System::shouldReceive('isDarkMode')->andReturn(false);
    PushNotifications::shouldReceive('clearBadge')->twice()->andThrow(new RuntimeException('plugin down'));
    fakeTabScreenRequests(badgeResetStatus: 500);
    $component = Native::test(AlertsScreen::class);

    app(MarkForegroundPush::class)->handle(new ForegroundPushReceived);
    $this->travel(5)->seconds();
    $component->firePoll('pollPushBadge')->assertDontSee('plugin down');
});

test('signing out forgets a pending foreground-push flag', function () {
    $state = new PushState;
    $state->markForegroundPush();

    $state->clear();

    expect($state->pullForegroundPush())->toBeFalse();
});

test('pulling the foreground flag reports it once and clears it', function () {
    $state = new PushState;
    $state->markForegroundPush();

    expect($state->pullForegroundPush())->toBeTrue()
        ->and($state->pullForegroundPush())->toBeFalse();
});
