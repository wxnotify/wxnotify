<?php

namespace App\Actions\Map;

use App\Models\ActiveWwa;
use App\Support\WarningColors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The part the map's warning queries share: which areas are in effect and what each one's geometry
 * is, and the payload an event is returned as. Areas are read from active_wwa_areas (never the
 * event row's own ugc_codes/polygon): an area's geometry is its own polygon, else the boundary of
 * the zone it names, found by UGC code in the public zones, fire zones, counties and marine zones.
 */
class ActiveWarningAreas
{
    /**
     * The `candidate`, `zone_boundary` and `resolved` CTEs (to follow a WITH), each materialised so
     * the geometry is resolved once: `candidate` is every active, unexpired area of an active,
     * non-test event that meets `$eventCondition` (SQL over `w`, the active_wwas row, and `pr`, its
     * catalog product, or NULLs); each distinct zone code without a polygon is looked up once in
     * `zone_boundary`; `resolved` is one row per area that has a geometry: `area_id`,
     * `active_wwa_id`, `expires` and `geom` (SRID 4269, unsimplified). The expiry comparison binds
     * Carbon's now(), so a frozen test clock and its fixtures agree.
     *
     * @param  list<mixed>  $eventBindings
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function ctes(string $eventCondition, array $eventBindings = []): array
    {
        $sql = <<<SQL
            candidate AS MATERIALIZED (
                SELECT a.id AS area_id, a.active_wwa_id, a.ugc_code, a.expires, a.polygon
                FROM active_wwa_areas a
                JOIN active_wwas w ON w.id = a.active_wwa_id
                LEFT JOIN products pr ON pr.id = w.product_id
                WHERE a.status = 'Active'
                  AND (a.expires IS NULL OR a.expires > ?)
                  AND w.status = 'Active'
                  AND w.is_test = false
                  AND {$eventCondition}
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
            )
            SQL;

        return ['sql' => $sql, 'bindings' => [now()->toDateTimeString(), ...$eventBindings]];
    }

    /**
     * The events of the given ids, keyed by id, with what their payload reads.
     *
     * @param  list<int>  $ids
     * @return Collection<int, ActiveWwa>
     */
    public function events(array $ids): Collection
    {
        return ActiveWwa::query()
            ->with(['product', 'latestIngestedProduct'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
    }

    /**
     * One event's payload from its area rows (each with `expires` and the simplified `geojson`). The
     * end is the latest of its areas', or none when any area has none. Identical geometries are
     * listed once: a storm-based warning's one polygon is stored on every zone row it covers.
     *
     * @param  Collection<int, object>  $areaRows
     * @return array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array<string, mixed>}
     */
    public function payload(ActiveWwa $wwa, string $family, Collection $areaRows): array
    {
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
                'geometries' => $areaRows->unique('geojson')->map(fn ($row): array => json_decode($row->geojson, true))->values()->all(),
            ],
        ];
    }
}
