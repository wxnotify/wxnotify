<?php

use App\NativeComponents\MapSpikeScreen;
use App\Support\TokenStore;
use Native\Mobile\Testing\Native;

test('the spike screen shows a web view whose page carries Leaflet, the USGS tiles and the NOAA radar', function () {
    (new TokenStore)->put('9|secret');

    Native::test(MapSpikeScreen::class)
        ->assertNavTitle('Map spike')
        ->assertElement('webview', fn (array $node): bool => str_contains((string) ($node['props']['html'] ?? ''), 'L.map(')
            && str_contains($node['props']['html'], 'basemap.nationalmap.gov')
            && str_contains($node['props']['html'], 'opengeo.ncep.noaa.gov')
            && ($node['props']['javascript'] ?? false) === true);
});
