<?php

namespace App\Actions\Map;

use App\Models\ActiveWwa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The warnings and watches drawn on the app's map: active events with an active, unexpired area
 * within the configured distance of any of the given points. Which areas are in effect and their
 * geometry come from ActiveWarningAreas (shared with the Live map's GetLiveWarnings). An event is
 * returned once with its areas' simplified geometries as one GeoJSON GeometryCollection.
 */
class GetMapWarnings
{
    private const METERS_PER_MILE = 1609.344;

    /** Smallest radius of curvature of the WGS84 ellipsoid (metres); a conservative angle for the search box. */
    private const MIN_EARTH_RADIUS_METERS = 6335000.0;

    /** Anchors beyond this many (after de-duplication) are ignored; the caller puts the phone first. */
    private const MAX_ANCHORS = 50;

    private const FAMILY_RANK = ['warning' => 0, 'watch' => 1];

    public function __construct(private ActiveWarningAreas $areas = new ActiveWarningAreas) {}

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

        $events = $this->areas->events($rows->keys()->all());

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
     * One event's payload (ActiveWarningAreas::payload()) from its area rows, plus the private
     * `_meters` (its nearest area's distance, used to sort and stripped before returning). Null when
     * the event is gone or, authoritatively, is not a warning or watch (the SQL family filter is only
     * a safe superset of this check).
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

        return [
            ...$this->areas->payload($wwa, $family, $areaRows),
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
        $bindings = [];

        foreach ($anchors as $anchor) {
            array_push($bindings, ...$this->searchBox($anchor, $radiusMeters));
        }

        $bindings[] = $radiusMeters;
        $bindings[] = (float) config('wxnotify.map.simplify_degrees');

        $areas = $this->areas->ctes(
            "COALESCE(pr.family, CASE upper(w.vtec_significance) WHEN 'W' THEN 'warning' WHEN 'A' THEN 'watch' END) IN ('warning', 'watch')",
        );

        return DB::select(<<<SQL
            WITH {$areas['sql']},
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
            SQL, [...$areas['bindings'], ...$bindings]);
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
