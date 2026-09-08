<?php

namespace App\Observers;

use App\Models\Location;

/**
 * Nulls the cached zone/county UGC codes whenever a Location's coordinates
 * change, so MatchWeatherAlertRules's lazy ResolveLocationZones call
 * re-resolves them against the new point rather than silently matching
 * against a stale zone after a subscriber moves their pin.
 */
class LocationZoneObserver
{
    public function updating(Location $location): void
    {
        if ($location->isDirty(['lat', 'lng'])) {
            $location->forecast_zone_ugc = null;
            $location->county_ugc = null;
            $location->fire_weather_zone_ugc = null;
        }
    }
}
