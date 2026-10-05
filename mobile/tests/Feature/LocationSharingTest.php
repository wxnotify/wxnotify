<?php

use App\Services\LocationSharing;
use App\Services\SignOut;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

function enabledResponse(array $overrides = []): array
{
    return ['data' => $overrides + ['location_id' => 7, 'beacon_token' => '12|beacon', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]];
}

test('turning it on asks the server, keeps the beacon token and starts the native beacon', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(enabledResponse())]);

    $outcome = app(LocationSharing::class)->enable();

    expect($outcome)->toBe('started')
        ->and((new LocationSharingState)->wanted())->toBeTrue()
        ->and((new TokenStore)->beaconToken())->toBe('12|beacon')
        ->and((new TokenStore)->beaconUrl())->toBe('https://wx.test/api/v1/location')
        ->and($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0])->toMatchArray([
            'url' => 'https://wx.test/api/v1/location',
            'token' => '12|beacon',
            'interval' => 300,
            'expiresAt' => null,
            'fineAccuracy' => false,
        ]);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'https://wx.test/api/v1/location-sharing');
});

test('a plan at its location limit is reported and nothing starts', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['error' => 'location_limit', 'message' => 'x'], 422)]);

    expect(app(LocationSharing::class)->enable())->toBe('limit')
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and($this->geolocation->shared)->toBeEmpty();
});

test('a server error starts nothing and keeps sharing off', function (int $status, string $outcome) {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], $status)]);

    expect(app(LocationSharing::class)->enable())->toBe($outcome)
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and($this->geolocation->shared)->toBeEmpty();
})->with([[503, 'failed'], [500, 'failed']]);

test('a phone with no network starts nothing and says it is offline', function () {
    $this->network->connected = false;
    Http::fake();

    expect(app(LocationSharing::class)->enable())->toBe('offline')
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and($this->geolocation->shared)->toBeEmpty();
    Http::assertNothingSent();
});

test('the state is derived from the native watch and the wanted flag', function () {
    $sharing = app(LocationSharing::class);

    expect($sharing->state()['status'])->toBe('off');

    (new LocationSharingState)->markWanted();
    expect($sharing->state()['status'])->toBe('waiting');

    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'lastShareAt' => '2026-10-05T14:03:22Z', 'lastShareStatus' => 204];
    expect($sharing->state())->toMatchArray(['status' => 'on', 'interval_minutes' => 5, 'last_shared_at' => '2026-10-05T14:03:22Z', 'while_using_only' => false]);

    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000, 'authorization' => 'whenInUse'];
    expect($sharing->state()['while_using_only'])->toBeTrue();
});

test('turning it off tells the server, stops the native watch and forgets the token', function () {
    Http::fake([
        'wx.test/api/v1/location-sharing' => Http::sequence()->push(enabledResponse())->push(null, 204),
    ]);
    $sharing = app(LocationSharing::class);
    $sharing->enable();
    $this->geolocation->stopped = [];

    expect($sharing->disable())->toBeTrue()
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and((new TokenStore)->beaconUrl())->toBeNull()
        ->and($this->geolocation->stopped)->toBe([['id' => 'wxnotify-location', 'clearBuffer' => true]]);
});

test('it stays on when the server could not be told it is off', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::sequence()->push(enabledResponse())->push([], 503)]);
    $sharing = app(LocationSharing::class);
    $sharing->enable();
    $this->geolocation->stopped = [];

    expect($sharing->disable())->toBeFalse()
        ->and((new LocationSharingState)->wanted())->toBeTrue()
        ->and((new TokenStore)->beaconToken())->toBe('12|beacon')
        ->and($this->geolocation->stopped)->toBeEmpty();
});

test('a revoked sign-in turns sharing off on this phone', function () {
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon', 'https://wx.test/api/v1/location');
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], 401)]);

    expect(app(LocationSharing::class)->disable())->toBeTrue()
        ->and((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull();
});

test('on launch an inactive native watch is re-armed with a fresh token', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::sequence()
        ->push(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 300]])
        ->push(enabledResponse(['beacon_token' => '13|fresh']))]);
    (new LocationSharingState)->markWanted();

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0]['token'])->toBe('13|fresh')
        ->and((new TokenStore)->beaconToken())->toBe('13|fresh');
});

test('on launch a changed interval restarts the beacon with the stored token and url', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 900]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon', 'https://wx.test/api/v1/location');
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000];

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toHaveCount(1)
        ->and($this->geolocation->shared[0])->toMatchArray(['url' => 'https://wx.test/api/v1/location', 'token' => '12|beacon', 'interval' => 900])
        ->and($this->geolocation->stopped)->toBe([['id' => 'wxnotify-location', 'clearBuffer' => false]]);
});

test('on launch a healthy beacon is left alone', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => true, 'location_id' => 7, 'interval_seconds' => 300]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon', 'https://wx.test/api/v1/location');
    $this->geolocation->status = ['id' => 'wxnotify-location', 'shareActive' => true, 'shareIntervalMs' => 300000];

    app(LocationSharing::class)->refreshOnLaunch();

    expect($this->geolocation->shared)->toBeEmpty()->and($this->geolocation->stopped)->toBeEmpty();
});

test('on launch an unlinked location stops the watch and turns sharing off', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['linked' => false, 'location_id' => null, 'interval_seconds' => null]])]);
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon', 'https://wx.test/api/v1/location');

    app(LocationSharing::class)->refreshOnLaunch();

    expect((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and($this->geolocation->stopped)->toHaveCount(1);
});

test('on launch nothing happens while sharing is off or when the server cannot be reached', function () {
    Http::fake();
    app(LocationSharing::class)->refreshOnLaunch();
    Http::assertNothingSent();

    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], 503)]);
    (new LocationSharingState)->markWanted();
    app(LocationSharing::class)->refreshOnLaunch();

    expect((new LocationSharingState)->wanted())->toBeTrue()->and($this->geolocation->shared)->toBeEmpty();
});

test('signing out stops the native watch, forgets the token and the flag even offline', function () {
    (new LocationSharingState)->markWanted();
    (new TokenStore)->putBeaconToken('12|beacon', 'https://wx.test/api/v1/location');
    $this->network->connected = false;
    Http::fake();

    app(SignOut::class)();

    expect((new LocationSharingState)->wanted())->toBeFalse()
        ->and((new TokenStore)->beaconToken())->toBeNull()
        ->and($this->geolocation->stopped)->toBe([['id' => 'wxnotify-location', 'clearBuffer' => true]]);
});
