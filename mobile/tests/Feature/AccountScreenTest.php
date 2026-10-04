<?php

use App\Models\CachedAlert;
use App\NativeComponents\AccountScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
