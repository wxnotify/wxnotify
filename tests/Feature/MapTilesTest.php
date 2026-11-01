<?php

use App\Enums\Integration;
use App\Enums\MapTileProvider;
use App\Settings\IntegrationSettings;
use App\Settings\SecretSettings;
use App\Support\ApplyMapTileSettings;
use App\Support\MapTiles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

const TILES_OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILES_OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
const TILES_FAKE_KEY = 'FAKE_tiles_key_123';

function tilesConfigure(string $provider, string $style, ?string $key = TILES_FAKE_KEY): void
{
    setIntegrationSetting('map_tiles_enabled', true);
    setIntegrationSetting('map_tiles_provider', $provider);
    setIntegrationSetting('map_tiles_style', $style);

    $secrets = app(SecretSettings::class);
    $secrets->map_tiles_api_key = $key;
    $secrets->save();
}

test('the map tiler is off by default with no provider, style or key', function () {
    $settings = app(IntegrationSettings::class);

    expect($settings->map_tiles_enabled)->toBeFalse()
        ->and($settings->map_tiles_provider)->toBeNull()
        ->and($settings->map_tiles_style)->toBeNull()
        ->and(app(SecretSettings::class)->map_tiles_api_key)->toBeNull()
        ->and(SecretSettings::encrypted())->toContain('map_tiles_api_key')
        ->and(Integration::MapTiles->enabledProperty())->toBe('map_tiles_enabled');
});

test('with the toggle off every map uses OpenStreetMap exactly as before, with no fallback', function () {
    expect(MapTiles::forFrontend())->toBe([
        'provider' => 'OpenStreetMap',
        'url' => TILES_OSM_URL,
        'attribution' => TILES_OSM_ATTRIBUTION,
        'dark_url' => null,
        'options' => ['maxZoom' => 19, 'tileSize' => 256, 'zoomOffset' => 0, 'detectRetina' => false],
        'fallback' => null,
    ]);
});

test('a stored provider, style and key are ignored while the toggle is off', function () {
    tilesConfigure('maptiler', 'streets-v4');
    setIntegrationSetting('map_tiles_enabled', false);

    expect(MapTiles::forFrontend()['url'])->toBe(TILES_OSM_URL);
});

test('each provider and style builds its documented raster tile url with the key', function (string $provider, string $style, string $url) {
    tilesConfigure($provider, $style);

    $tiles = MapTiles::forFrontend();

    expect($tiles['url'])->toBe($url)
        ->and($tiles['provider'])->toBe(MapTileProvider::from($provider)->label())
        ->and($tiles['dark_url'])->toBeNull()
        ->and($tiles['options'])->toBe(['maxZoom' => 19, 'tileSize' => 256, 'zoomOffset' => 0, 'detectRetina' => false])
        ->and($tiles['fallback'])->toBe([
            'url' => TILES_OSM_URL,
            'attribution' => TILES_OSM_ATTRIBUTION,
            'options' => ['maxZoom' => 19, 'tileSize' => 256, 'zoomOffset' => 0, 'detectRetina' => false],
        ]);
})->with([
    'maptiler streets' => ['maptiler', 'streets-v4', 'https://api.maptiler.com/maps/streets-v4/256/{z}/{x}/{y}{r}.png?key=FAKE_tiles_key_123'],
    'maptiler outdoor' => ['maptiler', 'outdoor-v4', 'https://api.maptiler.com/maps/outdoor-v4/256/{z}/{x}/{y}{r}.png?key=FAKE_tiles_key_123'],
    'maptiler satellite' => ['maptiler', 'hybrid', 'https://api.maptiler.com/maps/hybrid/256/{z}/{x}/{y}{r}.jpg?key=FAKE_tiles_key_123'],
    'mapbox streets' => ['mapbox', 'streets-v12', 'https://api.mapbox.com/styles/v1/mapbox/streets-v12/tiles/256/{z}/{x}/{y}{r}?access_token=FAKE_tiles_key_123'],
    'mapbox outdoors' => ['mapbox', 'outdoors-v12', 'https://api.mapbox.com/styles/v1/mapbox/outdoors-v12/tiles/256/{z}/{x}/{y}{r}?access_token=FAKE_tiles_key_123'],
    'mapbox satellite' => ['mapbox', 'satellite-streets-v12', 'https://api.mapbox.com/styles/v1/mapbox/satellite-streets-v12/tiles/256/{z}/{x}/{y}{r}?access_token=FAKE_tiles_key_123'],
    'stadia smooth' => ['stadia', 'alidade_smooth', 'https://tiles.stadiamaps.com/tiles/alidade_smooth/{z}/{x}/{y}{r}.png?api_key=FAKE_tiles_key_123'],
    'stadia outdoors' => ['stadia', 'outdoors', 'https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}{r}.png?api_key=FAKE_tiles_key_123'],
    'stadia satellite' => ['stadia', 'alidade_satellite', 'https://tiles.stadiamaps.com/tiles/alidade_satellite/{z}/{x}/{y}{r}.jpg?api_key=FAKE_tiles_key_123'],
    'thunderforest atlas' => ['thunderforest', 'atlas', 'https://tile.thunderforest.com/atlas/{z}/{x}/{y}{r}.png?apikey=FAKE_tiles_key_123'],
    'thunderforest outdoors' => ['thunderforest', 'outdoors', 'https://tile.thunderforest.com/outdoors/{z}/{x}/{y}{r}.png?apikey=FAKE_tiles_key_123'],
    'thunderforest transport' => ['thunderforest', 'transport', 'https://tile.thunderforest.com/transport/{z}/{x}/{y}{r}.png?apikey=FAKE_tiles_key_123'],
]);

test('each provider credits itself and OpenStreetMap with links, and satellite styles credit the imagery', function (string $provider, string $style, array $credits) {
    tilesConfigure($provider, $style);

    $attribution = MapTiles::forFrontend()['attribution'];

    expect($attribution)->toContain('https://www.openstreetmap.org/copyright');

    foreach ($credits as $credit) {
        expect($attribution)->toContain($credit);
    }
})->with([
    'maptiler' => ['maptiler', 'streets-v4', ['href="https://www.maptiler.com/copyright/"', '&copy; MapTiler']],
    'mapbox' => ['mapbox', 'streets-v12', ['href="https://www.mapbox.com/about/maps/"', 'Improve this map']],
    'mapbox satellite' => ['mapbox', 'satellite-streets-v12', ['href="https://www.maxar.com/"']],
    'stadia' => ['stadia', 'alidade_smooth', ['href="https://stadiamaps.com/attribution/"', 'href="https://openmaptiles.org/"']],
    'stadia satellite' => ['stadia', 'alidade_satellite', ['CNES, Distribution Airbus DS', 'PlanetObserver']],
    'thunderforest' => ['thunderforest', 'atlas', ['href="https://www.thunderforest.com/"', 'Thunderforest']],
]);

test('a key with special characters is url-encoded so it cannot break the url or add a placeholder', function () {
    tilesConfigure('thunderforest', 'atlas', 'a b&c={z}/#?');

    expect(MapTiles::forFrontend()['url'])->toBe('https://tile.thunderforest.com/atlas/{z}/{x}/{y}{r}.png?apikey=a%20b%26c%3D%7Bz%7D%2F%23%3F');
});

test('an incomplete or invalid configuration falls back to OpenStreetMap', function (string $provider, string $style, ?string $key) {
    tilesConfigure($provider, $style, $key);

    expect(MapTiles::forFrontend()['url'])->toBe(TILES_OSM_URL)
        ->and(MapTiles::forFrontend()['fallback'])->toBeNull();
})->with([
    'no key' => ['maptiler', 'streets-v4', null],
    'blank key' => ['maptiler', 'streets-v4', '   '],
    'unknown provider' => ['bing', 'streets-v4', TILES_FAKE_KEY],
    'style of another provider' => ['maptiler', 'atlas', TILES_FAKE_KEY],
]);

test('the styles offered depend on the provider', function () {
    expect(MapTileProvider::MapTiler->styles())->toBe(['streets-v4' => 'Streets', 'outdoor-v4' => 'Outdoor', 'hybrid' => 'Satellite (with labels)'])
        ->and(MapTileProvider::Mapbox->styles())->toBe(['streets-v12' => 'Streets', 'outdoors-v12' => 'Outdoors', 'satellite-streets-v12' => 'Satellite (with streets)'])
        ->and(MapTileProvider::StadiaMaps->styles())->toBe(['alidade_smooth' => 'Alidade Smooth', 'outdoors' => 'Outdoors', 'alidade_satellite' => 'Satellite'])
        ->and(MapTileProvider::Thunderforest->styles())->toBe(['atlas' => 'Atlas', 'outdoors' => 'Outdoors', 'transport' => 'Transport'])
        ->and(MapTileProvider::options())->toBe(['maptiler' => 'MapTiler', 'mapbox' => 'Mapbox', 'stadia' => 'Stadia Maps', 'thunderforest' => 'Thunderforest']);
});

test('OpenStreetMap is used when the settings cannot be read', function () {
    tilesConfigure('maptiler', 'streets-v4');
    DB::table('settings')->whereIn('group', ['integrations', 'secrets'])->delete();
    Cache::flush();
    app()->forgetInstance(IntegrationSettings::class);
    app()->forgetInstance(SecretSettings::class);

    expect(MapTiles::forFrontend()['url'])->toBe(TILES_OSM_URL);
});

test('the location picker config follows the setting, with no dark tiles for a separate tiler', function () {
    tilesConfigure('stadia', 'outdoors');

    app(ApplyMapTileSettings::class)();

    expect(config('filament-pinpoint.leaflet.tile_url'))->toBe('https://tiles.stadiamaps.com/tiles/outdoors/{z}/{x}/{y}{r}.png?api_key=FAKE_tiles_key_123')
        ->and(config('filament-pinpoint.leaflet.tile_url_dark'))->toBeNull()
        ->and(config('filament-pinpoint.leaflet.tile_attribution'))->toContain('https://stadiamaps.com/attribution/');
});

test('the location picker config is OpenStreetMap by default and no longer reads LEAFLET_ env values', function () {
    app(ApplyMapTileSettings::class)();

    expect(config('filament-pinpoint.leaflet.tile_url'))->toBe(TILES_OSM_URL)
        ->and(config('filament-pinpoint.leaflet.tile_url_dark'))->toBeNull()
        ->and(config('filament-pinpoint.leaflet.tile_attribution'))->toBe(TILES_OSM_ATTRIBUTION)
        ->and(file_get_contents(config_path('filament-pinpoint.php')))->not->toContain('LEAFLET_TILE');
});

test('the location picker keeps OpenStreetMap when the settings cannot be read', function () {
    DB::table('settings')->where('group', 'integrations')->delete();
    Cache::flush();
    app()->forgetInstance(IntegrationSettings::class);
    config(['filament-pinpoint.leaflet.tile_url' => TILES_OSM_URL]);

    app(ApplyMapTileSettings::class)();

    expect(config('filament-pinpoint.leaflet.tile_url'))->toBe(TILES_OSM_URL);
});
