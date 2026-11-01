<?php

use App\Enums\Tier;
use App\Filament\Admin\Pages\AppSettings;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\Locations\Widgets\LocationsMapWidget;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use App\Models\ShapefileLayer;
use App\Models\User;
use App\Settings\SecretSettings;
use App\Support\ApplyMapTileSettings;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;

use function Pest\Livewire\livewire;

const TILES_VIEW_OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILES_VIEW_OSM_CREDIT = 'OpenStreetMap\u003C/a\u003E contributors';
const TILES_VIEW_THUNDERFOREST_URL = 'https://tile.thunderforest.com/transport/{z}/{x}/{y}{r}.png?apikey=FAKE_view_key';

function tilesViewUseThunderforest(): void
{
    tilesViewUse('thunderforest', 'transport');
}

function tilesViewUse(string $provider, string $style): void
{
    setIntegrationSetting('map_tiles_enabled', true);
    setIntegrationSetting('map_tiles_provider', $provider);
    setIntegrationSetting('map_tiles_style', $style);

    $secrets = app(SecretSettings::class);
    $secrets->map_tiles_api_key = 'FAKE_view_key';
    $secrets->save();

    app(ApplyMapTileSettings::class)();
}

/**
 * The partial prints its config as JSON with unescaped slashes, so a URL
 * appears verbatim; HTML in the attribution is hex-escaped.
 */
function tilesViewRender(string $map): string
{
    return match ($map) {
        'locations widget' => livewire(LocationsMapWidget::class)->html(),
        'traccar widget' => livewire(TraccarDevicesMapWidget::class)->html(),
        'shapefile map page' => test()->get(ShapefileLayerResource::getUrl('map', ['record' => ShapefileLayer::factory()->create()], panel: 'admin'))->getContent(),
    };
}

beforeEach(function () {
    $this->subscriber = User::factory()->create(['tier_override' => Tier::Pro]);
    $this->staff = User::factory()->create(['is_staff' => true]);
});

function tilesViewPanel(string $map): void
{
    $isAdmin = $map === 'shapefile map page';

    Filament::setCurrentPanel($isAdmin ? 'admin' : 'dashboard');
    test()->actingAs($isAdmin ? test()->staff : test()->subscriber);
}

dataset('maps', ['locations widget', 'traccar widget', 'shapefile map page']);

test('every map draws OpenStreetMap through the shared tile layer by default', function (string $map) {
    tilesViewPanel($map);

    $html = tilesViewRender($map);

    expect($html)->toContain('"url":"'.TILES_VIEW_OSM_URL.'"')
        ->toContain(TILES_VIEW_OSM_CREDIT)
        ->toContain('"fallback":null')
        ->toContain('window.wxTileLayer()')
        ->not->toContain("L.tileLayer('https://");
})->with('maps');

test('every map draws the configured tiler with its credit and the OpenStreetMap fallback', function (string $map) {
    tilesViewPanel($map);
    tilesViewUseThunderforest();

    $html = tilesViewRender($map);

    expect($html)->toContain('"url":"'.TILES_VIEW_THUNDERFOREST_URL.'"')
        ->toContain('https://www.thunderforest.com/')
        ->toContain('"fallback":{"url":"'.TILES_VIEW_OSM_URL.'"')
        ->toContain("on('tileerror'")
        ->toContain('window.wxTileLayer()');
})->with('maps');

test('the location picker page uses the configured tiler and loads the fallback before Leaflet', function () {
    tilesViewPanel('location picker');
    tilesViewUseThunderforest();

    $html = $this->get(LocationResource::getUrl('create', panel: 'dashboard'))->getContent();

    expect($html)->toContain(str_replace('/', '\/', TILES_VIEW_THUNDERFOREST_URL))
        ->toContain('L.TileLayer.addInitHook')
        ->toContain("on('tileerror'")
        ->toContain('"fallback":{"url":"'.TILES_VIEW_OSM_URL.'"')
        ->and(strpos($html, 'L.TileLayer.addInitHook'))->toBeLessThan(strpos($html, '</head>'));
});

test('the location picker page uses OpenStreetMap by default', function () {
    tilesViewPanel('location picker');

    $html = $this->get(LocationResource::getUrl('create', panel: 'dashboard'))->getContent();

    expect($html)->toContain(str_replace('/', '\/', TILES_VIEW_OSM_URL))
        ->not->toContain('thunderforest');
});

test('pages without a map never carry the tiler key, only the key-free fallback hook', function (string $page) {
    tilesViewUseThunderforest();
    [$panel, $user, $url] = match ($page) {
        'app settings as owner' => ['admin', User::factory()->create(['is_staff' => true, 'is_owner' => true]), fn (): string => AppSettings::getUrl(panel: 'admin')],
        'app settings as staff' => ['admin', test()->staff, fn (): string => AppSettings::getUrl(panel: 'admin')],
        'dashboard home' => ['dashboard', test()->subscriber, fn (): string => Dashboard::getUrl(panel: 'dashboard')],
        'alert rules list' => ['dashboard', test()->subscriber, fn (): string => AlertRuleResource::getUrl('index', panel: 'dashboard')],
    };
    Filament::setCurrentPanel($panel);
    $this->actingAs($user);

    $this->get($url())
        ->assertOk()
        ->assertDontSee('FAKE_view_key', false)
        ->assertSee('"match_prefix":"https://tile.thunderforest.com/transport/"', false)
        ->assertSee('L.TileLayer.addInitHook', false);
})->with(['app settings as owner', 'app settings as staff', 'dashboard home', 'alert rules list']);

test('every page that renders a map carries the tile url with the key', function (string $page) {
    tilesViewUseThunderforest();
    [$panel, $user, $url] = match ($page) {
        'locations list' => ['dashboard', test()->subscriber, fn (): string => LocationResource::getUrl('index', panel: 'dashboard')],
        'location create' => ['dashboard', test()->subscriber, fn (): string => LocationResource::getUrl('create', panel: 'dashboard')],
        'traccar servers list' => ['dashboard', test()->subscriber, fn (): string => TraccarServerResource::getUrl('index', panel: 'dashboard')],
        'shapefile map' => ['admin', test()->staff, fn (): string => ShapefileLayerResource::getUrl('map', ['record' => ShapefileLayer::factory()->create()], panel: 'admin')],
    };
    Filament::setCurrentPanel($panel);
    $this->actingAs($user);

    $html = $this->get($url())->assertOk()->getContent();

    expect(str_contains($html, TILES_VIEW_THUNDERFOREST_URL) || str_contains($html, str_replace('/', '\/', TILES_VIEW_THUNDERFOREST_URL)))->toBeTrue();
})->with(['locations list', 'location create', 'traccar servers list', 'shapefile map']);

test('the provider logo control is wired for Mapbox and MapTiler only, and removed on fallback', function (?string $provider, ?string $style, ?string $logo) {
    if ($provider !== null) {
        tilesViewUse($provider, $style);
    }
    tilesViewPanel('locations widget');

    $html = livewire(LocationsMapWidget::class)->html();

    expect($html)->toContain("L.control({ position: 'bottomleft' })")
        ->toContain('layer.wxLogoControl.remove()');

    if ($logo === null) {
        expect($html)->toContain('"logo":null');
    } else {
        expect($html)->toContain($logo);
    }
})->with([
    'mapbox' => ['mapbox', 'outdoors-v12', '/images/map-logos/mapbox-logo.svg","alt":"Mapbox logo"'],
    'maptiler' => ['maptiler', 'streets-v4', '"logo":{"href":"https://www.maptiler.com/","src":"https://api.maptiler.com/resources/logo.svg"'],
    'stadia' => ['stadia', 'outdoors', null],
    'thunderforest' => ['thunderforest', 'atlas', null],
    'off' => [null, null, null],
]);

test('only an authentication or quota answer to the probe switches the map', function () {
    tilesViewPanel('locations widget');

    expect(livewire(LocationsMapWidget::class)->html())->toContain('[401, 403, 429].includes(response.status)');
});
