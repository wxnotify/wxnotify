<?php

use App\Models\CachedAlert;
use App\NativeComponents\AccountScreen;
use App\Support\LocalCache;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;
use Native\Mobile\Testing\Native;

$profile = ['id' => 5, 'name' => 'Pat Smith', 'email' => 'pat@example.com', 'tier' => ['value' => 'plus', 'label' => 'Plus'], 'unit_system' => 'imperial', 'timezone' => 'America/Chicago'];

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the account shows the subscriber, their plan and the app version', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    config(['nativephp.version' => '1.2.3']);

    Native::test(AccountScreen::class)
        ->assertSee('Pat Smith')
        ->assertSee('pat@example.com')
        ->assertSee('Plus')
        ->assertSee('1.2.3')
        ->assertHasTab('Account');

    expect((new LocalCache)->profile()['timezone'])->toBe('America/Chicago');
});

test('offline, the remembered profile is shown', function () use ($profile) {
    (new LocalCache)->rememberProfile($profile);
    $this->network->connected = false;
    Http::fake();

    Native::test(AccountScreen::class)->assertSee('Pat Smith');
});

test('signing out revokes the token on the server, forgets it, wipes the cache and returns to sign in', function () use ($profile) {
    (new LocalCache)->replaceFeed([['id' => 1, 'subject' => 'x', 'dispatched_at' => now()->toIso8601String(), 'channels' => [], 'has_failure' => false]]);
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/auth/token');
    expect((new TokenStore)->token())->toBeNull()
        ->and(CachedAlert::count())->toBe(0)
        ->and((new LocalCache)->profile())->toBeNull();
});

test('signing out works offline: the phone is cleared even though the server could not be told', function () use ($profile) {
    (new LocalCache)->rememberProfile($profile);
    $this->network->connected = false;
    Http::fake();

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a refused token returns to sign in', function () {
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AccountScreen::class)->assertReplacedWith('/sign-in');
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AccountScreen::class)->assertReplacedWith('/sign-in');
});

test('the push row says Off with a Turn on button until push is enabled', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');

    Native::test(AccountScreen::class)
        ->assertSee('Push notifications')
        ->assertSee('Off')
        ->assertSee('Turn on')
        ->assertDontSee('Turn off')
        ->assertSee('Choose which alerts go to this phone in your alert rules at wxnotify.com.');
});

test('the push row says On with a Turn off button while push is on and allowed', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->assertSee('On')->assertSee('Turn off')->assertDontSee('Turn on');
});

test('Turn on enrolls the device', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');
    PushNotifications::shouldReceive('enroll')->once();

    Native::test(AccountScreen::class)->tap('Turn on');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('Turn off removes the phone from the server and flips the row', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response(null, 204),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->tap('Turn off')->assertSee('Turn on');

    expect((new PushState)->wantsPush())->toBeFalse();
});

test('Turn off that cannot reach the server says so and stays on', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response([], 503),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->tap('Turn off')->assertSee('Couldn\'t turn push off')->assertSee('Turn off');

    expect((new PushState)->wantsPush())->toBeTrue();
});

test('when the OS refused notifications and push is off the row points only at the system settings', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('denied');
    System::shouldReceive('appSettings')->once();

    Native::test(AccountScreen::class)
        ->assertSee('Blocked in system settings')
        ->assertDontSee('Turn on')
        ->assertDontSee('Turn off')
        ->tap('Open system settings');
});

test('when the OS refused notifications but push is still on, the subscriber can also turn it off in the app', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response(null, 204),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('denied');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)
        ->assertSee('Blocked in system settings')
        ->assertSee('Open system settings')
        ->assertSee('Turn off')
        ->tap('Turn off');

    expect((new PushState)->wantsPush())->toBeFalse();
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/push-token');
});

test('Turn off after the session expired signs the subscriber out', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/push-token' => Http::response([], 401),
    ]);
    PushNotifications::shouldReceive('checkPermission')->andReturn('granted');
    (new PushState)->markEnabled();

    Native::test(AccountScreen::class)->tap('Turn off')->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});
