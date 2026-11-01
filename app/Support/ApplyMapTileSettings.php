<?php

namespace App\Support;

/**
 * Points the Pinpoint location picker (config filament-pinpoint.leaflet.*)
 * at the tiles MapTiles chooses. Runs at boot and never throws (MapTiles
 * falls back to OpenStreetMap when the settings cannot be read). Skipped while
 * caching config so a settings-derived URL, which carries the tiler's key, is
 * never baked into the cached config file. Only web requests render maps, so
 * long-lived queue workers need no restart when the setting changes.
 */
class ApplyMapTileSettings
{
    public function __invoke(): void
    {
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        $tiles = MapTiles::forFrontend();

        config([
            'filament-pinpoint.leaflet.tile_url' => $tiles['url'],
            'filament-pinpoint.leaflet.tile_url_dark' => $tiles['dark_url'],
            'filament-pinpoint.leaflet.tile_attribution' => $tiles['attribution'],
        ]);
    }
}
