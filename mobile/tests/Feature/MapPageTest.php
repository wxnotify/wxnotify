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

test('the map is sized from the window height, because a percentage height resolves to 0 in the Android web view', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect($html)->toContain('window.innerHeight')
        ->toContain("addEventListener('resize', fit)")
        ->not->toContain('#map { height: 100%');
});

function mapPageCsp(string $html): string
{
    preg_match('/http-equiv="Content-Security-Policy" content="([^"]*)"/', $html, $matches);

    return html_entity_decode($matches[1] ?? '');
}

function mapPageData(string $html): array
{
    preg_match('/var data = (.*);\n/', $html, $matches);

    return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
}

function mapPageLayers(array $urls): array
{
    return ['layers' => [
        'basemaps' => array_map(fn (string $url, int $index): array => ['key' => "b{$index}", 'label' => "B{$index}", 'url' => $url, 'max_zoom' => 10], $urls, array_keys($urls)),
        'radar' => null,
        'attribution' => '',
    ]];
}

test('the page restricts what it may load to inline code and the configured tile and radar hosts', function () {
    $html = (new MapPage)->html(mapPageSnapshot());

    expect(mapPageCsp($html))->toBe("default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: https://basemap.nationalmap.gov https://opengeo.ncep.noaa.gov")
        ->and($html)->not->toContain('unpkg.com');
});

test('the policy lists no host when the layers give no https host, and a plain http tile url adds none', function () {
    $none = (new MapPage)->html(mapPageSnapshot(['layers' => ['basemaps' => [], 'radar' => null, 'attribution' => '']]));
    $http = (new MapPage)->html(mapPageSnapshot(mapPageLayers(['http://tiles.example.test/{z}/{x}/{y}.png'])));

    expect(mapPageCsp($none))->toEndWith('img-src data: ')
        ->and(mapPageCsp($http))->toEndWith('img-src data: ');
});

test('a host from the layer configuration cannot add policy directives, and an explicit port is kept', function () {
    $hostile = (new MapPage)->html(mapPageSnapshot(mapPageLayers([
        'https://evil.test;script-src x/{z}/{x}/{y}.png',
        'https://evil.test; script-src x/{z}/{x}/{y}.png',
        "https://evil.test'/{z}/{x}/{y}.png",
    ])));
    $ported = (new MapPage)->html(mapPageSnapshot(mapPageLayers(['https://tiles.example.test:8443/{z}/{x}/{y}.png'])));

    expect(mapPageCsp($hostile))->toEndWith('img-src data: ')->not->toContain('script-src x')
        ->and(mapPageCsp($ported))->toEndWith('img-src data: https://tiles.example.test:8443');
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

test('with no centre the page carries a null centre and opens on the whole United States, otherwise on the centre', function () {
    $noCentre = ['center' => ['lat' => null, 'lng' => null, 'source' => null, 'label' => null], 'phone' => null, 'places' => [], 'warnings' => []];

    $none = (new MapPage)->html(mapPageSnapshot($noCentre));
    $centred = (new MapPage)->html(mapPageSnapshot());
    $script = (new MapPage(leafletJs: '', leafletCss: ''))->html(mapPageSnapshot());

    expect($none)->toContain('"center":{"lat":null')
        ->and($centred)->toContain('"center":{"lat":40')
        ->and($script)->toContain('data.center.lat !== null')
        ->toContain('map.fitBounds([[24, -125], [50, -66]])');
});

test('base-map names and attribution are escaped for Leaflet, which writes them as HTML, while place and warning text stays raw', function () {
    $name = '<b onmouseover=x>A&B</b>';
    $layers = mapPageLayers(['https://tiles.example.test/{z}/{x}/{y}.png']);
    $layers['layers']['basemaps'][0]['label'] = $name;
    $layers['layers']['attribution'] = $name;

    $data = mapPageData((new MapPage(leafletJs: '', leafletCss: ''))->html(mapPageSnapshot([
        ...$layers,
        'places' => [['id' => 1, 'label' => $name, 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]],
    ])));

    expect($data['basemaps'][0]['label'])->toBe(htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        ->toBe('&lt;b onmouseover=x&gt;A&amp;B&lt;/b&gt;')
        ->and($data['attribution'])->toBe('&lt;b onmouseover=x&gt;A&amp;B&lt;/b&gt;')
        ->and($data['places'][0]['label'])->toBe($name);
});

test('text that cannot be encoded fails loudly instead of producing a broken page', function () {
    $broken = ['places' => [['id' => 1, 'label' => "bad \xB1 text", 'type' => 'stationary', 'lat' => 40.1, 'lng' => -105.1, 'stale' => false]]];

    expect(fn () => (new MapPage)->html(mapPageSnapshot($broken)))->toThrow(JsonException::class);
});
