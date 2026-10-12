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
            ['id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => 'Issued', 'expires' => now()->addHour()->toIso8601String(), 'view_url' => 'https://wx.test/view-product/abc', 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]],
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
    $this->freezeTime();
    (new TokenStore)->put('9|secret');
});

test('the map is a full-height web view on its page with the Map tab active, and has no heading or list', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    Native::test(MapScreen::class)
        ->assertHasTab('Map')
        ->assertTabActive('Map')
        ->assertElement('webview', fn (array $node): bool => str_starts_with((string) ($node['props']['src'] ?? ''), '/map-page?v=') && ($node['props']['php'] ?? false) === true && ! isset($node['props']['html']))
        ->assertDontSee('Near you')
        ->assertDontSee('Tornado Warning')
        ->assertDontSee('Refresh')
        ->assertMissingElement('scroll_view');
});

test('offline it says the map needs a connection and shows no map', function () {
    $this->network->connected = false;

    Native::test(MapScreen::class)
        ->assertSee('The map needs a connection')
        ->assertMissingElement('webview');
});

test('a failing server shows a message with Try again', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['message' => 'boom'], 500)]);

    Native::test(MapScreen::class)->assertSee('WxNotify could not load the map. Try again in a moment.')->assertSee('Try again');
});

test('a refresh with the same places and warnings leaves the page identical, even when generated_at moves', function () {
    $calls = 0;
    Http::fake(['wx.test/api/v1/map' => function () use (&$calls) {
        $calls++;

        return Http::response(['data' => mapScreenSnapshot(['generated_at' => "2026-10-10T18:0{$calls}:00+00:00"])]);
    }]);

    $screen = Native::test(MapScreen::class);
    $before = $screen->get('pageUrl');

    $screen->call('refresh');

    expect($before)->toStartWith('/map-page?v=')
        ->and($screen->get('pageUrl'))->toBe($before);
    Http::assertSentCount(2);
});

test('a refresh with a changed snapshot rebuilds the page', function () {
    $calls = 0;
    Http::fake(['wx.test/api/v1/map' => function () use (&$calls) {
        $calls++;
        $places = mapScreenSnapshot()['places'];
        $places[0]['label'] = $calls === 1 ? 'Home' : 'Cabin';

        return Http::response(['data' => mapScreenSnapshot(['places' => $places])]);
    }]);

    $screen = Native::test(MapScreen::class);
    $before = $screen->get('pageUrl');

    $screen->call('refresh');

    expect($screen->get('pageUrl'))->not->toBe($before)
        ->and((new LocalCache)->mapPage())->toContain('Cabin');
    Http::assertSentCount(2);
});

test('going offline on a refresh removes the web view and says the map needs a connection', function () {
    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);

    $screen = Native::test(MapScreen::class)->assertElement('webview');

    $this->network->connected = false;

    $screen->call('refresh')
        ->assertMissingElement('webview')
        ->assertSee('The map needs a connection');
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

test('the map page route serves the cached page for the web view, with no caching, and 404s before a snapshot', function () {
    $this->get('/map-page')->assertNotFound();

    Http::fake(['wx.test/api/v1/map' => Http::response(['data' => mapScreenSnapshot()])]);
    Native::test(MapScreen::class);

    $this->get('/map-page')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=utf-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('L.map(', false)
        ->assertDontSee('9|secret');

    (new LocalCache)->wipe();

    $this->get('/map-page')->assertNotFound();
});
