<?php

use App\Models\CachedAlert;
use App\NativeComponents\AccountScreen;
use App\Services\AppBadge;
use App\Support\LocalCache;
use App\Support\LocationSharingState;
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
        ->assertTabActive('Settings');

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

test('signing out also forgets the cached rules, locations and form options', function () use ($profile) {
    $cache = new LocalCache;
    $cache->rememberRules([['id' => 1]]);
    $cache->rememberLocations([['id' => 2]], ['can_create' => true, 'limit' => 5]);
    $cache->rememberOptions(['categories' => []]);
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();
});

test('signing out forgets the feed\'s as_of and a pending mark all with its cutoff', function () use ($profile) {
    $cache = new LocalCache;
    $cache->rememberFeedAsOf('2026-10-20T12:00:00.123456Z');
    $cache->rememberPendingReadAll(through: '2026-10-20T12:00:00.123456Z');
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect($cache->feedAsOf())->toBeNull()
        ->and($cache->pendingReadAll())->toBeFalse()
        ->and($cache->pendingReadAllCutoff())->toBeNull();
});

test('signing out works offline: the phone is cleared even though the server could not be told', function () use ($profile) {
    (new LocalCache)->rememberProfile($profile);
    $this->network->connected = false;
    Http::fake();

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('signing out zeroes the icon badge, clears the tray through the plugin and calls no badge endpoint', function () use ($profile) {
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined')->byDefault();
    PushNotifications::shouldReceive('clearBadge')->once();
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect($this->appBadge->counts)->toBe([0]);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/push-token/badge'));
});

test('a failing icon badge still clears the tray and never stops the sign-out', function () use ($profile) {
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined')->byDefault();
    PushNotifications::shouldReceive('clearBadge')->once();
    app()->instance(AppBadge::class, new class extends AppBadge
    {
        public function set(int $count): void
        {
            throw new RuntimeException('badge down');
        }
    });
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a failing tray clear still zeroes the icon badge and never stops the sign-out', function () use ($profile) {
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined')->byDefault();
    PushNotifications::shouldReceive('clearBadge')->once()->andThrow(new RuntimeException('plugin down'));
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/auth/token' => Http::response(null, 204),
    ]);

    Native::test(AccountScreen::class)->tap('Sign out')->assertReplacedWith('/sign-in');

    expect($this->appBadge->counts)->toBe([0])
        ->and((new TokenStore)->token())->toBeNull();
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

function stubPushOff(): void
{
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined');
}

function sharingStatus(array $overrides = []): array
{
    return $overrides + ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareAt' => '2026-10-05T18:30:00+00:00', 'lastShareStatus' => 204, 'authorization' => 'always'];
}

test('the location row says Off with a Share location button that opens the explainer', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();

    Native::test(AccountScreen::class)
        ->assertSee('Share this phone\'s location')
        ->assertSee('Share location')
        ->assertDontSee('Stop sharing')
        ->tap('Share location')
        ->assertNavigatedTo('/share-location');
});

test('the location row shows the interval, the last shared time and Stop sharing while the native watch is running', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    (new LocationSharingState)->markWanted();
    $this->geolocation->status = sharingStatus();

    Native::test(AccountScreen::class)
        ->assertSee('On')
        ->assertSee('about every 5 minutes')
        ->assertSee('last shared 1:30 pm')
        ->assertSee('Stop sharing')
        ->assertDontSee('Share location')
        ->assertDontSee('Stops when you close the app');
});

test('a rejected share does not show a last shared time', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    $this->geolocation->status = sharingStatus(['lastShareStatus' => 401]);

    Native::test(AccountScreen::class)->assertSee('about every 5 minutes')->assertDontSee('last shared');
});

test('a malformed last shared time is left out instead of breaking the screen', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    $this->geolocation->status = sharingStatus(['lastShareAt' => 'not a time']);

    Native::test(AccountScreen::class)
        ->assertSee('about every 5 minutes')
        ->assertDontSee('last shared');
});

test('a running watch with no last shared time shows only the interval', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    $this->geolocation->status = sharingStatus(['lastShareAt' => null]);

    Native::test(AccountScreen::class)->assertSee('about every 5 minutes')->assertDontSee('last shared');
});

test('wanted with no running watch says it is waiting for permission and offers the system settings', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    (new LocationSharingState)->markWanted();
    System::shouldReceive('appSettings')->once();

    Native::test(AccountScreen::class)
        ->assertSee('Waiting for location permission')
        ->assertSee('Stop sharing')
        ->assertDontSee('Share location')
        ->tap('Open settings');
});

test('while-using authorization warns that sharing stops when the app closes', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    (new LocationSharingState)->markWanted();
    $this->geolocation->status = sharingStatus(['authorization' => 'whenInUse']);

    Native::test(AccountScreen::class)
        ->assertSee('Stops when you close the app')
        ->assertSee('Open settings')
        ->assertSee('Stop sharing');
});

test('Stop sharing tells the server, stops the watch and flips the row to Off', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/location-sharing' => Http::response(null, 204),
    ]);
    stubPushOff();
    (new LocationSharingState)->markWanted();
    $this->geolocation->status = sharingStatus();

    Native::test(AccountScreen::class)->tap('Stop sharing')->assertSee('Share location')->assertDontSee('Stop sharing');

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://wx.test/api/v1/location-sharing');
    expect((new LocationSharingState)->wanted())->toBeFalse()->and($this->geolocation->stopped)->not->toBeEmpty();
});

test('Stop sharing that cannot reach the server says so and stays On', function () use ($profile) {
    Http::fake([
        'wx.test/api/v1/me' => Http::response(['data' => $profile]),
        'wx.test/api/v1/location-sharing' => Http::response([], 503),
    ]);
    stubPushOff();
    (new LocationSharingState)->markWanted();
    $this->geolocation->status = sharingStatus();

    Native::test(AccountScreen::class)->tap('Stop sharing')->assertSee('Couldn\'t stop sharing')->assertSee('Stop sharing');

    expect((new LocationSharingState)->wanted())->toBeTrue()->and($this->geolocation->stopped)->toBeEmpty();
});

test('coming back to the screen re-renders from the current native status', function () use ($profile) {
    Http::fake(['wx.test/api/v1/me' => Http::response(['data' => $profile])]);
    stubPushOff();
    (new LocationSharingState)->markWanted();

    $screen = Native::test(AccountScreen::class)->assertSee('Waiting for location permission');

    $this->geolocation->status = sharingStatus();

    $screen->call('onResume')->assertSee('about every 5 minutes')->assertDontSee('Waiting for location permission');
});
