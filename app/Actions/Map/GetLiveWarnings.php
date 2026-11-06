<?php

namespace App\Actions\Map;

use App\Support\LiveWarningKinds;
use Illuminate\Support\Facades\DB;

/**
 * The events of one LiveWarningKinds kind drawn on the dashboard's Live map: active events with an
 * active, unexpired area whose geometry meets the viewer's box. Which areas are in effect and their
 * geometry come from ActiveWarningAreas (shared with the app's GetMapWarnings), and the payload is
 * the same. Ordered by end, soonest first (open-ended last), and capped; `truncated` says more
 * matched. The box comes from the browser, so it is always clamped to a bounded one first.
 */
class GetLiveWarnings
{
    public const MAX_WIDTH_DEGREES = 120.0;

    public const MAX_HEIGHT_DEGREES = 60.0;

    public function __construct(private ActiveWarningAreas $areas = new ActiveWarningAreas) {}

    /**
     * @param  array{south?: float, west?: float, north?: float, east?: float}  $bounds
     * @return array{events: list<array{id: int, event: string, family: string, color: string, headline: ?string, expires: ?string, view_url: ?string, geometry: array<string, mixed>}>, truncated: bool}
     */
    public function __invoke(array $bounds, string $kind): array
    {
        $limit = max(1, (int) config('wxnotify.map.max_live_warnings'));
        $rows = collect($this->areaRows($this->clamp($bounds), $kind, $limit + 1));
        $order = $rows->pluck('active_wwa_id')->unique()->values();
        $truncated = $order->count() > $limit;
        $order = $order->take($limit);

        if ($order->isEmpty()) {
            return ['events' => [], 'truncated' => false];
        }

        $rows = $rows->groupBy('active_wwa_id');
        $events = $this->areas->events($order->all());

        $entries = $order
            ->map(function (int $id) use ($events, $rows): ?array {
                $wwa = $events->get($id);

                return $wwa === null ? null : $this->areas->payload($wwa, (string) $wwa->eventFamily(), $rows->get($id));
            })
            ->filter()
            ->values()
            ->all();

        return ['events' => $entries, 'truncated' => $truncated];
    }

    /**
     * A bounded box from whatever the browser sent: a missing or non-finite edge becomes the world's,
     * each edge is kept within ±90/±180, reversed edges are swapped, and a box wider than 120 or
     * taller than 60 degrees is cut to that size around its centre.
     *
     * @param  array<string, mixed>  $bounds
     * @return array{south: float, west: float, north: float, east: float}
     */
    public function clamp(array $bounds): array
    {
        $edge = function (string $key, float $limit) use ($bounds): float {
            $value = $bounds[$key] ?? null;

            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                return $limit;
            }

            return max(-abs($limit), min(abs($limit), (float) $value));
        };

        [$south, $north] = [$edge('south', -90.0), $edge('north', 90.0)];
        [$west, $east] = [$edge('west', -180.0), $edge('east', 180.0)];
        [$south, $north] = [min($south, $north), max($south, $north)];
        [$west, $east] = [min($west, $east), max($west, $east)];

        [$south, $north] = $this->shrink($south, $north, self::MAX_HEIGHT_DEGREES);
        [$west, $east] = $this->shrink($west, $east, self::MAX_WIDTH_DEGREES);

        return ['south' => $south, 'west' => $west, 'north' => $north, 'east' => $east];
    }

    /**
     * @return array{float, float}
     */
    private function shrink(float $low, float $high, float $span): array
    {
        if ($high - $low <= $span) {
            return [$low, $high];
        }

        $centre = ($low + $high) / 2;

        return [$centre - $span / 2, $centre + $span / 2];
    }

    /**
     * One row per area of the chosen events: its event, end and simplified GeoJSON, in the events'
     * order. Only the first `$eventLimit` events (by end, then id) are simplified and serialised; the
     * caller asks for one more than it returns to know whether there were more.
     *
     * @param  array{south: float, west: float, north: float, east: float}  $box
     * @return list<object>
     */
    private function areaRows(array $box, string $kind, int $eventLimit): array
    {
        $phenomena = LiveWarningKinds::phenomena($kind);
        $areas = $this->areas->ctes(
            'w.vtec_significance = ? AND w.vtec_phenomenon IN ('.implode(', ', array_fill(0, count($phenomena), '?')).')',
            [LiveWarningKinds::significance($kind), ...$phenomena],
        );

        return DB::select(<<<SQL
            WITH {$areas['sql']},
            box AS (
                SELECT ST_MakeEnvelope(?::float8, ?::float8, ?::float8, ?::float8, 4269) AS geom
            ),
            inside AS MATERIALIZED (
                SELECT r.area_id, r.active_wwa_id, r.expires
                FROM resolved r, box
                WHERE r.geom && box.geom AND ST_Intersects(r.geom, box.geom)
            ),
            chosen AS (
                SELECT active_wwa_id, CASE WHEN bool_or(expires IS NULL) THEN NULL ELSE MAX(expires) END AS ends
                FROM inside
                GROUP BY active_wwa_id
                ORDER BY ends ASC NULLS LAST, active_wwa_id
                LIMIT ?
            )
            SELECT i.area_id, i.active_wwa_id, i.expires,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(r.geom, ?), 4, 0) AS geojson
            FROM chosen c
            JOIN inside i ON i.active_wwa_id = c.active_wwa_id
            JOIN resolved r ON r.area_id = i.area_id
            ORDER BY c.ends ASC NULLS LAST, c.active_wwa_id, i.area_id
            SQL, [
            ...$areas['bindings'],
            $box['west'], $box['south'], $box['east'], $box['north'],
            $eventLimit,
            (float) config('wxnotify.map.simplify_degrees'),
        ]);
    }
}
