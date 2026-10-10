<?php

use App\NativeComponents\LocationsScreen;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function listedLocation(array $overrides = []): array
{
    return [
        'id' => 1, 'label' => 'Home', 'type' => 'stationary', 'source' => null, 'source_label' => null, 'address' => '1 Main Street, Norman',
        'lat' => 35.2, 'lng' => -97.4, 'timezone' => 'America/Chicago', 'update_interval_minutes' => null, 'linked' => null, 'device_name' => null,
        'has_missing_device' => false, 'has_fix' => true, 'last_fix_at' => null, 'position_updated_at' => null, 'stale' => null,
        ...$overrides,
    ];
}

function locationsResponse(array $locations, array $meta = ['can_create' => true, 'limit' => 5]): array
{
    return ['data' => $locations, 'meta' => $meta];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the list shows each location with what kind it is and how current it is', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([
        listedLocation(),
        listedLocation(['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'address' => null, 'linked' => true, 'stale' => false]),
        listedLocation(['id' => 3, 'label' => 'Old phone', 'type' => 'mobile', 'source' => 'app', 'source_label' => 'WxNotify app', 'address' => null, 'linked' => false, 'has_missing_device' => true, 'stale' => true]),
        listedLocation(['id' => 4, 'label' => 'Truck', 'type' => 'mobile', 'source' => 'traccar', 'source_label' => 'Traccar', 'address' => null, 'device_name' => 'F-150', 'stale' => true]),
    ]))]);

    Native::test(LocationsScreen::class)
        ->assertSee('Home')
        ->assertSee('1 Main Street, Norman')
        ->assertSee('Pixel 9')
        ->assertSee('WxNotify app · Current')
        ->assertSee('Old phone')
        ->assertSee('WxNotify app · Not linked to a phone')
        ->assertSee('Truck')
        ->assertSee('Traccar · No recent update')
        ->assertHasTab('Locations')
        ->assertTabActive('Locations');
});

test('the list is remembered for offline use, and offline shows it with a note', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()]))]);

    Native::test(LocationsScreen::class);

    expect((new LocalCache)->locations())->toBe([listedLocation()])->and((new LocalCache)->locationsMeta())->toBe(['can_create' => true, 'limit' => 5]);

    $this->network->connected = false;

    Native::test(LocationsScreen::class)->assertSee('Home')->assertSee('offline');
});

test('offline with nothing remembered shows only the offline note, not an empty list', function () {
    Http::fake();
    $this->network->connected = false;

    Native::test(LocationsScreen::class)
        ->assertSee('You\'re offline.')
        ->assertDontSee('No locations yet');
});

test('with no locations the screen explains how to add one', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([]))]);

    Native::test(LocationsScreen::class)->assertSee('No locations yet')->assertSee('Add location');
});

test('maintenance while loading shows the server\'s message with a retry', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::sequence()
        ->push('Back soon.', 503, ['Content-Type' => 'text/plain'])
        ->push(locationsResponse([listedLocation()])),
    ]);

    Native::test(LocationsScreen::class)
        ->assertSee('Back soon.')
        ->assertDontSee('No locations yet')
        ->tap('Try again')
        ->assertDontSee('Back soon.')
        ->assertSee('Home');
});

test('tapping a location opens its editor and the add button opens a new one', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()]))]);

    Native::test(LocationsScreen::class)->tap('location-1')->assertNavigatedTo('/locations/1');
    Native::test(LocationsScreen::class)->tap('Add location')->assertNavigatedTo('/locations/new');
});

test('at the plan limit the add button explains the limit instead of opening the editor, until the list refreshes', function () {
    Http::fake(['wx.test/api/v1/locations' => Http::response(locationsResponse([listedLocation()], ['can_create' => false, 'limit' => 1]))]);

    Native::test(LocationsScreen::class)
        ->tap('Add location')
        ->assertSee('Your plan allows 1 location')
        ->assertNoNavigation()
        ->call('refresh')
        ->assertDontSee('Your plan allows 1 location');
});

test('a refused token returns to sign in and a signed-out visitor is sent there', function () {
    Http::fake(['*' => Http::response([], 401)]);
    Native::test(LocationsScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->forget();
    Native::test(LocationsScreen::class)->assertReplacedWith('/sign-in');
});
