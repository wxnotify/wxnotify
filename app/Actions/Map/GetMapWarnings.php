<?php

namespace App\Actions\Map;

use App\Models\ActiveWwa;
use App\Support\WarningColors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The warnings and watches drawn on the map: active events with an active, unexpired area within the
 * configured distance of any of the given points. Areas are read from active_wwa_areas (never the
 * event row's own ugc_codes/polygon): an area's geometry is its own polygon, else the boundary of the
 * zone it names, found by UGC code in the public zones, counties, fire zones and marine zones.
 * An event is returned once with its areas' simplified geometries as one GeoJSON GeometryCollection.
 */
class GetMapWarnings
{
    private const METERS_PER_MILE = 1609.344;

    /** Smallest radius of curvature of the WGS84 ellipsoid (metres); a conservative angle for the search box. */
    private const MIN_EARTH_RADIUS_METERS = 6335000.0;

    /** Anchors beyond this many (after de-duplication) are ignored; the caller puts the phone first. */
    private const MAX_ANCHORS = 50;

    private const FAMILY_RANK = ['warning' => 0, 'watch' => 1];

    /**
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array<string, mixed>}>
     */
    public function __invoke(array $anchors): array
    {
        $anchors = $this->unique($anchors);

        if ($anchors === []) {
            return [];
        }

        $rows = collect($this->areaRows($anchors))->groupBy('active_wwa_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $events = ActiveWwa::query()
            ->with(['product', 'latestIngestedProduct'])
            ->whereIn('id', $rows->keys()->all())
            ->get()
            ->keyBy('id');

        return $rows
            ->map(fn ($areaRows, $id): ?array => $this->entry($events->get($id), $areaRows))
            ->filter()
            ->sortBy([
                fn (array $a, array $b): int => self::FAMILY_RANK[$a['family']] <=> self::FAMILY_RANK[$b['family']],
                fn (array $a, array $b): int => $a['_meters'] <=> $b['_meters'],
                fn (array $a, array $b): int => $a['id'] <=> $b['id'],
            ])
            ->take((int) config('wxnotify.map.max_warnings'))
            ->map(fn (array $entry): array => collect($entry)->except('_meters')->all())
            ->values()
            ->all();
    }

    /**
     * One event's payload from its area rows, plus the private `_meters` (its nearest area's distance,
     * used to sort and stripped before returning). Null when the event is gone or, authoritatively, is
     * not a warning or watch (the SQL family filter is only a safe superset of this check). The end is
     * the latest of its areas', or none when any area has none.
     *
     * @param  Collection<int, object>  $areaRows
     * @return ?array<string, mixed>
     */
    private function entry(?ActiveWwa $wwa, $areaRows): ?array
    {
        $family = $wwa?->eventFamily();

        if ($wwa === null || ! isset(self::FAMILY_RANK[$family])) {
            return null;
        }

        $expires = $areaRows->contains(fn ($row): bool => $row->expires === null) ? null : $areaRows->max('expires');

        return [
            'id' => $wwa->id,
            'event' => $wwa->eventName(),
            'family' => $family,
            'color' => WarningColors::for($wwa->eventName(), $family),
            'headline' => $wwa->headline,
            'expires' => $expires === null ? null : Carbon::parse($expires)->toIso8601String(),
            'view_url' => $wwa->latestIngestedProduct?->viewUrl(),
            'geometry' => [
                'type' => 'GeometryCollection',
                'geometries' => $areaRows->map(fn ($row): array => json_decode($row->geojson, true))->values()->all(),
            ],
            '_meters' => (float) $areaRows->min('meters'),
        ];
    }

    /**
     * One row per qualifying area: its event, end, nearest distance to any anchor and simplified GeoJSON.
     *
     * The work is ordered so each expensive step runs as few times as possible: the cheap filters
     * (area and event state, warning/watch family) choose the areas; each distinct zone code is
     * resolved to a boundary once; each area's geometry is resolved once (materialised); a cheap
     * bounding-box test against each anchor's search box discards the far ones before the exact
     * distance; and only the areas inside the radius are simplified and serialised, once each.
     *
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<object>
     */
    private function areaRows(array $anchors): array
    {
        $radiusMeters = (float) config('wxnotify.map.warning_radius_miles') * self::METERS_PER_MILE;
        $values = implode(', ', array_fill(0, count($anchors), '(?::float8, ?::float8, ?::float8, ?::float8, ?::float8, ?::float8)'));
        $bindings = [now()->toDateTimeString()];

        foreach ($anchors as $anchor) {
            array_push($bindings, ...$this->searchBox($anchor, $radiusMeters));
        }

        $bindings[] = $radiusMeters;
        $bindings[] = (float) config('wxnotify.map.simplify_degrees');

        return DB::select(<<<SQL
            WITH candidate AS MATERIALIZED (
                SELECT a.id AS area_id, a.active_wwa_id, a.ugc_code, a.expires, a.polygon
                FROM active_wwa_areas a
                JOIN active_wwas w ON w.id = a.active_wwa_id
                LEFT JOIN products pr ON pr.id = w.product_id
                WHERE a.status = 'Active'
                  AND (a.expires IS NULL OR a.expires > ?)
                  AND w.status = 'Active'
                  AND w.is_test = false
                  AND COALESCE(pr.family, CASE upper(w.vtec_significance) WHEN 'W' THEN 'warning' WHEN 'A' THEN 'watch' END) IN ('warning', 'watch')
            ),
            zone_boundary AS MATERIALIZED (
                SELECT c.ugc_code, (
                    SELECT b.boundary FROM (
                        SELECT boundary FROM public_zones WHERE substr(c.ugc_code, 3, 1) = 'Z' AND state_zone = substr(c.ugc_code, 1, 2) || substr(c.ugc_code, 4)
                        UNION ALL
                        SELECT boundary FROM fire_zones WHERE substr(c.ugc_code, 3, 1) = 'Z' AND state_zone = substr(c.ugc_code, 1, 2) || substr(c.ugc_code, 4)
                        UNION ALL
                        SELECT boundary FROM counties WHERE substr(c.ugc_code, 3, 1) = 'C' AND state = substr(c.ugc_code, 1, 2) AND right(fips, 3) = substr(c.ugc_code, 4)
                        UNION ALL
                        SELECT boundary FROM marine_zones_coastal WHERE zone_id = c.ugc_code
                        UNION ALL
                        SELECT boundary FROM marine_zones_offshore WHERE zone_id = c.ugc_code
                        UNION ALL
                        SELECT boundary FROM marine_zones_high_seas WHERE zone_id = c.ugc_code
                    ) b LIMIT 1
                ) AS boundary
                FROM (SELECT DISTINCT ugc_code FROM candidate WHERE polygon IS NULL) c
            ),
            resolved AS MATERIALIZED (
                SELECT k.area_id, k.active_wwa_id, k.expires, COALESCE(k.polygon, z.boundary) AS geom
                FROM candidate k
                LEFT JOIN zone_boundary z ON k.polygon IS NULL AND z.ugc_code = k.ugc_code
                WHERE COALESCE(k.polygon, z.boundary) IS NOT NULL
            ),
            anchor AS (
                SELECT * FROM (VALUES {$values}) AS p(lng, lat, xmin, ymin, xmax, ymax)
            ),
            near AS (
                SELECT r.area_id, MIN(d.meters) AS meters
                FROM resolved r
                JOIN anchor p ON r.geom && ST_MakeEnvelope(p.xmin, p.ymin, p.xmax, p.ymax, 4269)
                CROSS JOIN LATERAL (
                    SELECT ST_Distance(ST_SetSRID(r.geom, 4326)::geography, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326)::geography) AS meters
                    OFFSET 0
                ) d
                WHERE d.meters <= ?
                GROUP BY r.area_id
            )
            SELECT r.area_id, r.active_wwa_id, r.expires, n.meters,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(r.geom, ?), 4, 0) AS geojson
            FROM near n
            JOIN resolved r ON r.area_id = n.area_id
            SQL, $bindings);
    }

    /**
     * The anchor and a box (xmin, ymin, xmax, ymax in degrees) that contains every point within the
     * radius of it: the angular radius is taken on the smallest-radius sphere the WGS84 ellipsoid
     * allows and padded by 2%, and the longitude half-width widens with latitude (asin(sin r / cos
     * lat)), up to the whole world near a pole or when the box would cross the antimeridian, so the
     * box is a safe superset that only ever discards areas that are certainly too far.
     *
     * @param  array{lat: float, lng: float}  $anchor
     * @return array{float, float, float, float, float, float}
     */
    private function searchBox(array $anchor, float $radiusMeters): array
    {
        $lat = $anchor['lat'];
        $lng = $anchor['lng'];
        $angle = $radiusMeters / self::MIN_EARTH_RADIUS_METERS;
        $halfHeight = rad2deg($angle) * 1.02;
        $ratio = sin($angle) / max(cos(deg2rad($lat)), 1e-9);
        $halfWidth = $ratio >= 1 ? 360.0 : rad2deg(asin($ratio)) * 1.02;

        if ($lng - $halfWidth < -180 || $lng + $halfWidth > 180) {
            return [$lng, $lat, -180.0, max(-90.0, $lat - $halfHeight), 180.0, min(90.0, $lat + $halfHeight)];
        }

        return [$lng, $lat, $lng - $halfWidth, max(-90.0, $lat - $halfHeight), $lng + $halfWidth, min(90.0, $lat + $halfHeight)];
    }

    /**
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<array{lat: float, lng: float}>
     */
    private function unique(array $anchors): array
    {
        return collect($anchors)
            ->map(fn (array $anchor): array => ['lat' => round((float) $anchor['lat'], 3), 'lng' => round((float) $anchor['lng'], 3)])
            ->unique(fn (array $anchor): string => $anchor['lat'].','.$anchor['lng'])
            ->take(self::MAX_ANCHORS)
            ->values()
            ->all();
    }
}
