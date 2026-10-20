<?php

use App\Services\LocationSharing;
use App\Services\SignOut;
use App\Services\Welcome;
use App\Services\WxNotifyApi;
use App\Support\LocationSharingState;
use App\Support\PushState;
use App\Support\TokenStore;
use App\Support\WelcomeState;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

beforeEach(function () {
    System::shouldReceive('isIos')->andReturn(false)->byDefault();
    PushNotifications::shouldReceive('checkPermission')->andReturn('not_determined')->byDefault();
});

function fakeWelcomeSlot(mixed $response): void
{
    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/alert-rule-options' => $response]);
}

function welcomeSlotLookups(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/v1/alert-rule-options'))->count();
}

test('the flow shows until it has been marked seen', function () {
    $welcome = app(Welcome::class);

    expect($welcome->shouldShow())->toBeTrue();

    (new WelcomeState)->markSeen();

    expect($welcome->shouldShow())->toBeFalse();
});

test('signing out keeps the seen flag', function () {
    (new TokenStore)->put('9|secret');
    (new WelcomeState)->markSeen();

    app(SignOut::class)->clearLocal();

    expect((new WelcomeState)->seen())->toBeTrue();
});

test('an install with a stored token and no flag is treated as seen', function () {
    (new TokenStore)->put('9|secret');

    app(Welcome::class)->markLegacyInstallSeen();

    expect(app(Welcome::class)->shouldShow())->toBeFalse();
});

test('a fresh install with no token is not', function () {
    app(Welcome::class)->markLegacyInstallSeen();

    expect(app(Welcome::class)->shouldShow())->toBeTrue();
});

test('steps include push and location when push is off and a slot is free', function () {
    fakeWelcomeSlot(Http::response(['data' => ['can_share_phone_location' => true]]));

    expect(app(Welcome::class)->steps())->toBe(['welcome', 'offer', 'push', 'location', 'done']);
});

test('steps skip push when push is already on or the OS denied it', function (string $permission, bool $enabled) {
    fakeWelcomeSlot(Http::response(['data' => ['can_share_phone_location' => true]]));
    PushNotifications::shouldReceive('checkPermission')->andReturn($permission);
    $enabled ? (new PushState)->markEnabled() : null;

    expect(app(Welcome::class)->steps())->toBe(['welcome', 'offer', 'location', 'done']);
})->with([
    'already on' => ['granted', true],
    'denied by the OS' => ['denied', false],
]);

test('steps skip location when the server says no slot, when the key is missing, and when the lookup throws', function (mixed $response) {
    fakeWelcomeSlot($response);

    expect(app(Welcome::class)->steps())->toBe(['welcome', 'offer', 'push', 'done']);
})->with([
    'no slot' => fn () => Http::response(['data' => ['can_share_phone_location' => false]]),
    'key missing' => fn () => Http::response(['data' => ['can_create_location' => true]]),
    'server error' => fn () => Http::response([], 500),
    'unauthenticated' => fn () => Http::response([], 401),
]);

test('steps skip location without asking the server when sharing is already on', function () {
    fakeWelcomeSlot(Http::response(['data' => ['can_share_phone_location' => true]]));
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareStatus' => 204];

    expect(app(Welcome::class)->steps())->toBe(['welcome', 'offer', 'push', 'done'])
        ->and(welcomeSlotLookups())->toBe(0);
});

test('the slot lookup remembers what the server answered', function (mixed $response, ?bool $slot) {
    fakeWelcomeSlot($response);
    $welcome = app(Welcome::class);

    $welcome->steps();

    expect($welcome->phoneSlot())->toBe($slot)
        ->and(welcomeSlotLookups())->toBe(1);
})->with([
    'free' => [fn () => Http::response(['data' => ['can_share_phone_location' => true]]), true],
    'no slot' => [fn () => Http::response(['data' => ['can_share_phone_location' => false]]), false],
    'key missing' => [fn () => Http::response(['data' => ['can_create_location' => true]]), null],
    'server error' => [fn () => Http::response([], 500), null],
    'rate limited' => [fn () => Http::response([], 429), null],
    'unauthenticated' => [fn () => Http::response([], 401), null],
    'timed out' => [fn () => Http::failedConnection('timed out'), null],
]);

test('the slot lookup is unknown when sharing is waiting, and the server is not asked', function () {
    fakeWelcomeSlot(Http::response(['data' => ['can_share_phone_location' => true]]));
    (new LocationSharingState)->markWanted();
    $welcome = app(Welcome::class);

    expect($welcome->steps())->toBe(['welcome', 'offer', 'push', 'done'])
        ->and($welcome->phoneSlot())->toBeNull()
        ->and(welcomeSlotLookups())->toBe(0);
});

test('the slot lookup gives up after a few seconds, while other calls keep the long timeout', function () {
    $timeouts = [];
    (new TokenStore)->put('9|secret');
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['data' => ['can_share_phone_location' => true]]);
    });

    app(Welcome::class)->steps();
    app(WxNotifyApi::class)->alertRuleOptions();

    expect($timeouts)->toBe([4, 15]);
});

test('a native failure while reading push or location state leaves that step out instead of throwing', function () {
    fakeWelcomeSlot(Http::response(['data' => ['can_share_phone_location' => true]]));
    PushNotifications::shouldReceive('checkPermission')->andThrow(new RuntimeException('bridge'));
    $this->mock(LocationSharing::class, fn ($mock) => $mock->shouldReceive('state')->andThrow(new RuntimeException('bridge')));
    $welcome = app(Welcome::class);

    expect($welcome->steps())->toBe(['welcome', 'offer', 'done'])
        ->and($welcome->phoneSlot())->toBeNull()
        ->and(welcomeSlotLookups())->toBe(0);
});
