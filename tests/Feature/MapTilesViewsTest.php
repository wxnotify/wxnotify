<?php

use App\Enums\Tier;
use App\Filament\Admin\Resources\ShapefileLayers\ShapefileLayerResource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\Locations\Widgets\LocationsMapWidget;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use App\Models\ShapefileLayer;
use App\Models\User;
use App\Settings\SecretSettings;
use App\Support\ApplyMapTileSettings;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

const TILES_VIEW_OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILES_VIEW_OSM_CREDIT = 'OpenStreetMap\u003C/a\u003E contributors';
const TILES_VIEW_THUNDERFOREST_URL = 'https://tile.thunderforest.com/transport/{z}/{x}/{y}{r}.png?apikey=FAKE_view_key';

function tilesViewUseThunderforest(): void
{
    setIntegrationSetting('map_tiles_enabled', true);
    setIntegrationSetting('map_tiles_provider', 'thunderforest');
    setIntegrationSetting('map_tiles_style', 'transport');

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
