<?php

namespace App\NativeComponents;

use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * THROWAWAY (slice 5 spike): proves an inline-HTML web view can run bundled Leaflet with the USGS
 * base map, the NOAA radar, a polygon, a marker and a popup. Removed in the Map screen task.
 */
class MapSpikeScreen extends NativeComponent
{
    use RequiresSignIn;

    public string $html = '';

    public function mount(): void
    {
        if ($this->redirectIfSignedOut()) {
            return;
        }

        $this->html = view('map.spike', [
            'leafletCss' => file_get_contents(resource_path('map/leaflet.css')),
            'leafletJs' => file_get_contents(resource_path('map/leaflet.js')),
        ])->render();
    }
}
