<?php

use App\Support\MapPage;
use App\Support\TokenStore;

function mapPageSnapshot(array $overrides = []): array
{
    return [
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel 9'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => '2026-10-10T18:00:00+00:00', 'stale' => false],
        'places' => [['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]],
        'warnings' => [[
            'id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => 'Tornado Warning issued',
            'expires' => '2026-10-10T19:00:00+00:00', 'view_url' => 'https://wx.test/view-product/abc',
            'geometry' => ['type' => 'GeometryCollection', 'geometries' => [['type' => 'Polygon', 'coordinates' => [[[-105.5, 39.5], [-105.5, 40.5], [-104.5, 40.5], [-104.5, 39.5], [-105.5, 39.5]]]]]],
        ]],
        'layers' => [
            'basemaps' => [['key' => 'topo', 'label' => 'Topo', 'url' => 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSTopo/MapServer/tile/{z}/{y}/{x}', 'max_zoom' => 16]],
            'radar' => ['wms_url' => 'https://opengeo.ncep.noaa.gov/geoserver/conus/conus_bref_qcd/ows', 'layer' => 'conus_bref_qcd'],
            'attribution' => 'USGS The National Map · NOAA/NWS MRMS',
        ],
        'generated_at' => '2026-10-10T18:00:00+00:00',
        ...$overrides,
    ];
}

test('the page carries Leaflet, the configured layers and the snapshot, and is the same for the same snapshot', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->toContain('L.map(')
        ->toContain('basemap.nationalmap.gov')
        ->toContain('conus_bref_qcd')
        ->toContain('Tornado Warning')
        ->toContain('USGS The National Map')
        ->and((new MapPage)->html(mapPageSnapshot()))->toBe($html);
});

test('the page restricts what it may load to inline code and the configured tile and radar hosts', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->toContain('Content-Security-Policy')
        ->toContain("default-src 'none'")
        ->toContain('https://basemap.nationalmap.gov')
        ->toContain('https://opengeo.ncep.noaa.gov')
        ->not->toContain('unpkg.com');
});

test('hostile text in a place name or a headline cannot close the script or inject markup', function () {
    $hostile = '</script><img src=x onerror=alert(1)>"\'&';
    $benign = (new MapPage)->html(mapPageSnapshot());

    $html = (new MapPage)->html(mapPageSnapshot([
        'places' => [['id' => 1, 'label' => $hostile, 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]],
        'warnings' => [[...mapPageSnapshot()['warnings'][0], 'headline' => $hostile, 'event' => $hostile]],
    ]));

    expect(substr_count($html, '</script>'))->toBe(substr_count($benign, '</script>'))
        ->and($html)->not->toContain('<img src=x')
        ->and($html)->toContain('\u003C/script\u003E');
});

test('the page never contains an app token', function () {
    (new TokenStore)->put('9|secret-token');

    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->not->toContain('secret-token')->not->toContain('Bearer');
});

test('the page\'s own script builds popups from text nodes and never uses innerHTML', function () {
    // Leaflet itself mentions innerHTML, so check the page with the library left out.
    $html = (new MapPage(leafletJs: '', leafletCss: ''))->html(mapPageSnapshot());

    expect($html)->toContain('createTextNode')->not->toContain('innerHTML');
});

test('with no centre the page opens on the whole United States', function () {
    $html = (new MapPage)->html(mapPageSnapshot(['center' => ['lat' => null, 'lng' => null, 'source' => null, 'label' => null], 'phone' => null, 'places' => [], 'warnings' => []]));

    expect($html)->toContain('fitBounds');
});
