<?php

namespace App\Support\Shapefiles;

use App\Models\Shapefiles\CountyBoundary;
use App\Models\Shapefiles\CwaBoundary;
use App\Models\Shapefiles\FireZone;
use App\Models\Shapefiles\MarineZoneCoastal;
use App\Models\Shapefiles\MarineZoneHighSeas;
use App\Models\Shapefiles\MarineZoneOffshore;
use App\Models\Shapefiles\PublicZone;
use App\Models\Shapefiles\UsStateBoundary;
use App\Models\Shapefiles\ZoneCountyCorrelation;

final class ShapefileLayerDefinitions
{
    /**
     * @return array<int, ShapefileLayerDefinition>
     */
    public static function all(): array
    {
        return [
            new ShapefileLayerDefinition(
                key: 'public_zones',
                name: 'Public Zones',
                sourceUrl: 'https://www.weather.gov/gis/PublicZones',
                format: 'shapefile',
                liveTable: 'public_zones',
                stagingTable: 'public_zones_staging',
                naturalKeyColumns: ['state_zone'],
                attributeMap: [
                    'STATE' => 'state', 'CWA' => 'cwa', 'TIME_ZONE' => 'time_zone',
                    'FE_AREA' => 'fe_area', 'ZONE' => 'zone', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'LON' => 'lon', 'LAT' => 'lat',
                    'SHORTNAME' => 'shortname',
                ],
                modelClass: PublicZone::class,
            ),
            new ShapefileLayerDefinition(
                key: 'counties',
                name: 'Counties',
                sourceUrl: 'https://www.weather.gov/gis/Counties',
                format: 'shapefile',
                liveTable: 'counties',
                stagingTable: 'counties_staging',
                naturalKeyColumns: ['fips'],
                attributeMap: [
                    'STATE' => 'state', 'CWA' => 'cwa', 'COUNTYNAME' => 'countyname',
                    'FIPS' => 'fips', 'TIME_ZONE' => 'time_zone', 'FE_AREA' => 'fe_area',
                    'LON' => 'lon', 'LAT' => 'lat',
                ],
                modelClass: CountyBoundary::class,
            ),
            new ShapefileLayerDefinition(
                key: 'cwa_boundaries',
                name: 'CWA Boundaries',
                sourceUrl: 'https://www.weather.gov/gis/CWABounds',
                format: 'shapefile',
                liveTable: 'cwa_boundaries',
                stagingTable: 'cwa_boundaries_staging',
                naturalKeyColumns: ['cwa'],
                attributeMap: [
                    'WFO' => 'wfo', 'CWA' => 'cwa', 'LON' => 'lon', 'LAT' => 'lat',
                    'REGION' => 'region', 'FULLSTAID' => 'fullstaid', 'CITYSTATE' => 'citystate',
                    'CITY' => 'city', 'STATE' => 'state', 'ST' => 'st',
                ],
                modelClass: CwaBoundary::class,
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_coastal',
                name: 'Marine Zones — Coastal',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_coastal',
                stagingTable: 'marine_zones_coastal_staging',
                naturalKeyColumns: ['zone_id'],
                attributeMap: self::marineAttributeMap(),
                modelClass: MarineZoneCoastal::class,
                zoneType: 'coastal',
                groupMatchText: 'Coastal',
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_offshore',
                name: 'Marine Zones — Offshore',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_offshore',
                stagingTable: 'marine_zones_offshore_staging',
                naturalKeyColumns: ['zone_id'],
                attributeMap: self::marineAttributeMap(),
                modelClass: MarineZoneOffshore::class,
                zoneType: 'offshore',
                groupMatchText: 'Offshore',
            ),
            new ShapefileLayerDefinition(
                key: 'marine_zones_high_seas',
                name: 'Marine Zones — High Seas',
                sourceUrl: 'https://www.weather.gov/gis/MarineZones',
                format: 'shapefile',
                liveTable: 'marine_zones_high_seas',
                stagingTable: 'marine_zones_high_seas_staging',
                naturalKeyColumns: ['name'],
                attributeMap: self::highSeasAttributeMap(),
                modelClass: MarineZoneHighSeas::class,
                zoneType: 'high_seas',
                groupMatchText: 'High Seas',
            ),
            new ShapefileLayerDefinition(
                key: 'fire_zones',
                name: 'Fire Zones',
                sourceUrl: 'https://www.weather.gov/gis/FireZones',
                format: 'shapefile',
                liveTable: 'fire_zones',
                stagingTable: 'fire_zones_staging',
                naturalKeyColumns: ['state_zone'],
                attributeMap: [
                    'STATE' => 'state', 'ZONE' => 'zone', 'CWA' => 'cwa', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'TIME_ZONE' => 'time_zone',
                    'FE_AREA' => 'fe_area', 'LON' => 'lon', 'LAT' => 'lat',
                ],
                modelClass: FireZone::class,
            ),
            new ShapefileLayerDefinition(
                key: 'us_states',
                name: 'US States',
                sourceUrl: 'https://www.weather.gov/gis/USStates',
                format: 'shapefile',
                liveTable: 'us_states',
                stagingTable: 'us_states_staging',
                naturalKeyColumns: ['state'],
                attributeMap: [
                    'STATE' => 'state', 'NAME' => 'name', 'FIPS' => 'fips',
                    'LON' => 'lon', 'LAT' => 'lat',
                ],
                modelClass: UsStateBoundary::class,
            ),
            new ShapefileLayerDefinition(
                key: 'zone_county_correlation',
                name: 'Zone–County Correlation',
                sourceUrl: 'https://www.weather.gov/gis/ZoneCounty',
                format: 'correlation',
                liveTable: 'zone_county_correlations',
                stagingTable: 'zone_county_correlations_staging',
                naturalKeyColumns: ['state_zone', 'fips'],
                attributeMap: [
                    'STATE' => 'state', 'ZONE' => 'zone', 'CWA' => 'cwa', 'NAME' => 'name',
                    'STATE_ZONE' => 'state_zone', 'COUNTY' => 'county', 'FIPS' => 'fips',
                    'TIME_ZONE' => 'time_zone', 'FE_AREA' => 'fe_area', 'LAT' => 'lat', 'LON' => 'lon',
                ],
                modelClass: ZoneCountyCorrelation::class,
            ),
        ];
    }

    public static function forKey(string $key): ShapefileLayerDefinition
    {
        $definition = collect(self::all())->firstWhere('key', $key);

        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown shapefile layer key: {$key}");
        }

        return $definition;
    }

    /**
     * @return array<string, string>
     */
    private static function marineAttributeMap(): array
    {
        return [
            'ID' => 'zone_id', 'WFO' => 'wfo', 'GL_WFO' => 'gl_wfo', 'NAME' => 'name',
            'AJOIN0' => 'ajoin0', 'AJOIN1' => 'ajoin1', 'LON' => 'lon', 'LAT' => 'lat',
        ];
    }

    /**
     * High Seas Marine Zones is genuinely a different real DBF schema than
     * Coastal/Offshore (verified against a real download): fields are
     * `WFO`, `NAME`, `LAT`, `LON`, a lowercase `id` that's empty for every
     * real record, and a `URL` field this app doesn't currently capture.
     * `zone_id` is mapped from that empty `id` field purely to satisfy the
     * live/staging tables' NOT NULL constraint on the column — it carries no
     * real data for this layer and is not part of the natural key (see the
     * migration that moved this table's unique constraint to `name`).
     *
     * @return array<string, string>
     */
    private static function highSeasAttributeMap(): array
    {
        return [
            'id' => 'zone_id', 'WFO' => 'wfo', 'NAME' => 'name', 'LON' => 'lon', 'LAT' => 'lat',
        ];
    }
}
