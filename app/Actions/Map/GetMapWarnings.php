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

    /** A generous index-friendly pre-filter in degrees; the exact test below is in metres. */
    private const PREFILTER_DEGREES = 6.0;

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
     * @param  list<array{lat: float, lng: float}>  $anchors
     * @return list<object>
     */
    private function areaRows(array $anchors): array
    {
        $values = implode(', ', array_fill(0, count($anchors), '(?::float8, ?::float8)'));
        $bindings = [(float) config('wxnotify.map.simplify_degrees')];

        foreach ($anchors as $anchor) {
            $bindings[] = $anchor['lng'];
            $bindings[] = $anchor['lat'];
        }

        $bindings[] = now()->toDateTimeString();
        $bindings[] = self::PREFILTER_DEGREES;
        $bindings[] = (float) config('wxnotify.map.warning_radius_miles') * self::METERS_PER_MILE;

        return DB::select(<<<SQL
            SELECT t.area_id, t.active_wwa_id, t.expires, MIN(t.meters) AS meters, MIN(t.geojson) AS geojson
            FROM (
                SELECT a.id AS area_id, a.active_wwa_id, a.expires,
                       ST_Distance(ST_SetSRID(g.geom, 4326)::geography, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326)::geography) AS meters,
                       ST_AsGeoJSON(ST_SimplifyPreserveTopology(g.geom, ?), 4) AS geojson
                FROM active_wwa_areas a
                JOIN active_wwas w ON w.id = a.active_wwa_id
                JOIN LATERAL (
                    SELECT COALESCE(a.polygon, (
                        SELECT b.boundary FROM (
                            SELECT boundary FROM public_zones WHERE substr(a.ugc_code, 3, 1) = 'Z' AND state_zone = substr(a.ugc_code, 1, 2) || substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM fire_zones WHERE substr(a.ugc_code, 3, 1) = 'Z' AND state_zone = substr(a.ugc_code, 1, 2) || substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM counties WHERE substr(a.ugc_code, 3, 1) = 'C' AND state = substr(a.ugc_code, 1, 2) AND right(fips, 3) = substr(a.ugc_code, 4)
                            UNION ALL
                            SELECT boundary FROM marine_zones_coastal WHERE zone_id = a.ugc_code
                            UNION ALL
                            SELECT boundary FROM marine_zones_offshore WHERE zone_id = a.ugc_code
                            UNION ALL
                            SELECT boundary FROM marine_zones_high_seas WHERE zone_id = a.ugc_code
                        ) b LIMIT 1
                    )) AS geom
                ) g ON g.geom IS NOT NULL
                CROSS JOIN (VALUES {$values}) AS p(lng, lat)
                WHERE a.status = 'Active'
                  AND (a.expires IS NULL OR a.expires > ?)
                  AND w.status = 'Active'
                  AND w.is_test = false
                  AND ST_DWithin(g.geom, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4269), ?)
                  AND ST_DWithin(ST_SetSRID(g.geom, 4326)::geography, ST_SetSRID(ST_MakePoint(p.lng, p.lat), 4326)::geography, ?)
            ) t
            GROUP BY t.area_id, t.active_wwa_id, t.expires
            SQL, $bindings);
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
            ->values()
            ->all();
    }
}
