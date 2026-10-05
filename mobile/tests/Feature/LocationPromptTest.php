<?php

use App\NativeComponents\LocationPrompt;
use App\Support\LocationSharingState;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the explainer says what is shared and offers Share and Not now', function () {
    Native::test(LocationPrompt::class)
        ->assertSee('Share this phone\'s location')
        ->assertSee('only the latest position every few minutes')
        ->assertSee('no history')
        ->assertSee('choose "Always" or "Allow all the time" if your phone offers it')
        ->assertSee('sharing may stop when you close the app')
        ->assertSee('Android shows a notification while sharing is on')
        ->assertDontSee('about every 5 minutes')
        ->assertSee('Share my location')
        ->assertSee('Not now');
});

test('opening the explainer starts nothing', function () {
    Http::fake();

    Native::test(LocationPrompt::class);

    Http::assertNothingSent();
    expect($this->geolocation->shared)->toBeEmpty();
});

test('Share starts the beacon and returns to Account', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['data' => ['location_id' => 7, 'beacon_token' => '12|b', 'url' => 'https://wx.test/api/v1/location', 'interval_seconds' => 300]])]);

    Native::test(LocationPrompt::class)->tap('Share my location')->assertReplacedWith('/account');

    expect((new LocationSharingState)->wanted())->toBeTrue()->and($this->geolocation->shared)->toHaveCount(1);
});

test('a plan at its limit is explained and nothing starts', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response(['error' => 'location_limit', 'message' => 'x'], 422)]);

    Native::test(LocationPrompt::class)
        ->tap('Share my location')
        ->assertSee('location limit')
        ->assertNoNavigation();

    expect((new LocationSharingState)->wanted())->toBeFalse();
});

test('a failed start says so and stays on the screen', function () {
    Http::fake(['wx.test/api/v1/location-sharing' => Http::response([], 503)]);

    Native::test(LocationPrompt::class)->tap('Share my location')->assertSee('Couldn\'t start sharing')->assertNoNavigation();

    expect((new LocationSharingState)->wanted())->toBeFalse();
});

test('an offline start says so and stays on the screen', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(LocationPrompt::class)->tap('Share my location')->assertSee('you appear to be offline')->assertNoNavigation();
});

test('Not now returns to Account without asking the server', function () {
    Http::fake();

    Native::test(LocationPrompt::class)->tap('Not now')->assertReplacedWith('/account');

    Http::assertNothingSent();
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(LocationPrompt::class)->assertReplacedWith('/sign-in');
});
