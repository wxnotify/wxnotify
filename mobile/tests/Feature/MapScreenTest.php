<?php

use App\NativeComponents\MapScreen;
use App\Support\LocalCache;
use App\Support\MapPage;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function mapScreenSnapshot(array $overrides = []): array
{
    return [
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel 9'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => '2026-10-10T18:00:00+00:00', 'stale' => false],
        'places' => [
            ['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false],
            ['id' => 2, 'label' => 'Pixel 9', 'type' => 'mobile', 'lat' => 40.0, 'lng' => -105.0, 'stale' => true],
        ],
        'warnings' => [
            ['id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => 'Issued', 'expires' => '2026-10-10T19:00:00+00:00', 'view_url' => 'https://wx.test/view-product/abc', 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]],
            ['id' => 8, 'event' => 'Tornado Watch', 'family' => 'watch', 'color' => '#ffff00', 'headline' => null, 'expires' => null, 'view_url' => null, 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]],
        ],
        'layers' => [
            'basemaps' => [['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16]],
            'radar' => ['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'],
            'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
        ],
        'generated_at' => '2026-10-10T18:00:00+00:00',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the map shows a web view with the page, the Near you list, and the Map tab as active', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)
        ->assertNavTitle('Map')
        ->assertHasTab('Map')
        ->assertTabActive('Map')
        ->assertElement('webview', fn (array $node): bool => str_contains((string) ($node['props']['html'] ?? ''), 'L.map(') && ($node['props']['javascript'] ?? false) === true)
        ->assertSee('Near you')
        ->assertSee('Tornado Warning')
        ->assertSee('Tornado Watch')
        ->assertSee('Home')
        ->assertSee('No recent update');
});

test('a warning row opens its product page in the in-app browser, and one without a page is not tappable', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)->tap('warning-0');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc']);

    Native::test(MapScreen::class)->assertMissingElement('pressable', fn (array $p) => ($p['ref'] ?? null) === 'warning-1');
});

test('a place row opens its location screen', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)->tap('place-1')->assertNavigatedTo('/locations/1');
});

test('without a phone position the screen suggests turning on location sharing', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot(['phone' => null, 'center' => ['lat' => 40.1, 'lng' => -105.1, 'source' => 'place', 'label' => 'Home']])])]);

    Native::test(MapScreen::class)->assertSee('Turn on location sharing in Account to center the map on this phone');
});

test('with nothing to show it says so and still shows the map', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot(['phone' => null, 'places' => [], 'warnings' => [], 'center' => ['lat' => null, 'lng' => null, 'source' => null, 'label' => null]])])]);

    Native::test(MapScreen::class)
        ->assertSee('No places to show yet')
        ->assertElement('webview');
});

test('offline it says the map needs a connection and lists the last snapshot', function () {
    (new LocalCache)->rememberMap(mapScreenSnapshot());
    $this->network->connected = false;

    Native::test(MapScreen::class)
        ->assertSee('The map needs a connection')
        ->assertSee('You\'re offline')
        ->assertSee('Tornado Warning')
        ->assertSee('Home')
        ->assertMissingElement('webview');
});

test('offline with nothing remembered it only says the map needs a connection', function () {
    $this->network->connected = false;

    Native::test(MapScreen::class)->assertSee('The map needs a connection')->assertDontSee('No places to show yet');
});

test('a failing server shows a message with Try again', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['message' => 'boom'], 500)]);

    Native::test(MapScreen::class)->assertSee('WxNotify could not load the map. Try again in a moment.')->assertSee('Try again');
});

test('Refresh refetches the snapshot, and the page only changes when the snapshot does', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    $screen = Native::test(MapScreen::class);
    $before = $screen->get('html');

    $screen->tap('refresh-map');

    expect($screen->get('html'))->toBe($before);
    Http::assertSentCount(2);
});

test('a signed-out visitor is sent to Sign in, and a rejected token signs out', function () {
    (new TokenStore)->forget();
    Native::test(MapScreen::class)->assertReplacedWith('/sign-in');

    (new TokenStore)->put('9|secret');
    Http::fake(['wx.test/api/v1/map' => Http::response([], 401)]);
    Native::test(MapScreen::class)->assertReplacedWith('/sign-in');
});

test('a snapshot the map page cannot encode shows the generic failure message instead of crashing', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);
    app()->bind(MapPage::class, fn () => new class extends MapPage
    {
        public function html(array $snapshot): string
        {
            throw new JsonException('Malformed UTF-8 characters');
        }
    });

    Native::test(MapScreen::class)
        ->assertSee('WxNotify could not load the map. Try again in a moment.')
        ->assertSee('Try again')
        ->assertMissingElement('webview');
});
