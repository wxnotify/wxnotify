<?php

use App\Support\MapTiles;

return [
    /*
    |--------------------------------------------------------------------------
    | Map Provider
    |--------------------------------------------------------------------------
    |
    | Choose which map provider to use: 'google' or 'leaflet'.
    | - 'google' requires a Google Maps API key (default, most accurate)
    | - 'leaflet' uses OpenStreetMap + Nominatim, completely free
    |
    */
    'provider' => env('PINPOINT_PROVIDER', 'google'),

    /*
    |--------------------------------------------------------------------------
    | Google Maps API Key
    |--------------------------------------------------------------------------
    |
    | Your Google Maps API key. You can get one from the Google Cloud Console.
    | Make sure to enable the following APIs:
    | - Maps JavaScript API
    | - Places API
    | - Geocoding API
    |
    | Only required when provider = 'google'.
    |
    */
    'api_key' => env('GOOGLE_MAPS_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Leaflet Settings
    |--------------------------------------------------------------------------
    |
    | Settings used when provider = 'leaflet'.
    |
    | tile_url, tile_url_dark and tile_attribution are NOT read from .env: they
    | default to OpenStreetMap here and are replaced at boot from App settings
    | (Integrations > Map tiles) by App\Support\ApplyMapTileSettings, so the
    | location picker uses the same tiles as every other map (App\Support\MapTiles).
    |
    | nominatim_url  - Nominatim API base URL for search & reverse geocoding.
    |                  For production heavy-usage, consider self-hosting or
    |                  using a commercial provider (MapTiler, etc).
    |
    */
    'leaflet' => [
        'tile_url' => MapTiles::OSM_URL,
        'tile_url_dark' => null,
        'tile_attribution' => MapTiles::OSM_ATTRIBUTION,
        'nominatim_url' => env('NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Map Settings
    |--------------------------------------------------------------------------
    |
    | These are the default settings for the map picker. You can override
    | these values when using the component.
    |
    */
    'default' => [
        'lat' => env('GOOGLE_MAPS_DEFAULT_LAT', -0.5050),
        'lng' => env('GOOGLE_MAPS_DEFAULT_LNG', 117.1500),
        'zoom' => env('GOOGLE_MAPS_DEFAULT_ZOOM', 13),
        'height' => env('GOOGLE_MAPS_DEFAULT_HEIGHT', 400),
        'radius' => env('GOOGLE_MAPS_DEFAULT_RADIUS', 500),
    ],
];
