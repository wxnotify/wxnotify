<?php

namespace App\Actions\Weather;

use App\Models\Location;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a Location's forecast zone, county, and fire weather zone UGC
 * codes locally via PostGIS ST_Contains, against the AWIPS Shapefile
 * Management feature's public_zones/counties/fire_zones tables — replacing
 * what the original design sourced from the external api.weather.gov/points
 * API. Shapefile geometry is stored SRID 4269 (NAD83); the query point is
 * built directly in that SRID rather than transformed from 4326, since the
 * datum difference is sub-meter and irrelevant at zone-polygon scale.
 *
 * Each table's natural key isn't itself in NWS UGC format: public_zones and
 * fire_zones store state_zone as e.g. "NY001" (no type letter), and counties
 * has no zone-letter code at all, only a 5-digit FIPS. Real UGC codes
 * ("NYZ001"/"NYC001") are derived here from the state + zone/fips columns.
 *
 * The three columns are a derived cache, not a subscriber edit, and this runs
 * on read paths (the My Alerts "Active now" widget), so it saves quietly: no
 * maintenance-mode block (BlockedDuringMaintenance) and no activity-log row.
 */
class ResolveLocationZones
{
    public function __invoke(Location $location): void
    {
        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        $location->forceFill([
            'forecast_zone_ugc' => $this->resolveZoneUgc('public_zones', $lng, $lat),
            'county_ugc' => $this->resolveCountyUgc($lng, $lat),
            'fire_weather_zone_ugc' => $this->resolveZoneUgc('fire_zones', $lng, $lat),
        ])->saveQuietly();
    }

    private function resolveZoneUgc(string $table, float $lng, float $lat): ?string
    {
        $row = DB::selectOne(
            "SELECT state, zone FROM {$table} WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1",
            [$lng, $lat],
        );

        return $row === null ? null : strtoupper($row->state).'Z'.$row->zone;
    }

    private function resolveCountyUgc(float $lng, float $lat): ?string
    {
        $row = DB::selectOne(
            'SELECT state, fips FROM counties WHERE ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4269)) LIMIT 1',
            [$lng, $lat],
        );

        return $row === null ? null : strtoupper($row->state).'C'.substr($row->fips, -3);
    }
}
