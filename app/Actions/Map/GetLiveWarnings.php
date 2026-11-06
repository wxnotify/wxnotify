<?php

namespace App\Actions\Map;

use App\Support\LiveWarningKinds;
use Illuminate\Support\Facades\DB;

/**
 * The events of one LiveWarningKinds kind drawn on the dashboard's Live map: active events with an
 * active, unexpired area whose geometry meets the viewer's box. Which areas are in effect and their
 * geometry come from ActiveWarningAreas (shared with the app's GetMapWarnings), and the payload is
 * the same, except that a watch's rows are merged into one event (see areaRows()) and an event's
 * end is over all its active areas, not only those in view. Ordered by end, soonest first
 * (open-ended last), and capped; `truncated` says more events matched. The box comes from the browser, so it is always clamped to a bounded one first.
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
        $order = $rows->pluck('event_id')->unique()->values();
        $truncated = $order->count() > $limit;
        $order = $order->take($limit);

        if ($order->isEmpty()) {
            return ['events' => [], 'truncated' => false];
        }

        $rows = $rows->groupBy('event_id');
        $events = $this->areas->events($rows->only($order->all())->map(fn ($eventRows): int => $eventRows->first()->latest_wwa_id)->values()->all());

        $entries = $order
            ->map(function (int $id) use ($events, $rows): ?array {
                $eventRows = $rows->get($id);
                $wwa = $events->get($eventRows->first()->latest_wwa_id);

                return $wwa === null ? null : [...$this->areas->payload($wwa, (string) $wwa->eventFamily(), $eventRows), 'id' => $id];
            })
            ->filter()
            ->values()
            ->all();

        return ['events' => $entries, 'truncated' => $truncated];
    }

    /**
     * A bounded box from whatever the browser sent: a missing or non-finite edge becomes the world's
     * and each edge is kept within ±90/±180. Reversed latitudes are swapped. A west edge east of the
     * east edge is a view across the antimeridian: like the page's script, the west edge is kept and
     * the box stops at 180 (the part beyond is not read). A box wider than 120 or taller than 60
     * degrees is then cut to that size around its centre.
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

        if ($west > $east) {
            $east = 180.0;
        }

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
     * One row per in-box area of the chosen events: the event's id, the row its payload is read
     * from (`latest_wwa_id`), the event's end (`expires`, over ALL its active areas, in the box or
     * not, so panning never changes it) and the area's simplified GeoJSON, in the events' order.
     * Only the first `$eventLimit` events (by end, then id) are simplified and serialised; the
     * caller asks for one more than it returns to know whether there were more.
     *
     * A short-fuse event is one active_wwas row. A watch is one row per WFO (from its WCN, the
     * county authority, following that office's cancellations) plus SPC's KWNS row, whose counties
     * do not follow them: the KWNS row is used only while no WFO row of the watch exists, and the
     * rows of one watch (phenomenon, number, year) are one event whose id is the lowest id of those
     * rows (so it does not depend on which rows have areas in view or arrived first) and whose
     * payload is read from its most recently updated row.
     *
     * @param  array{south: float, west: float, north: float, east: float}  $box
     * @return list<object>
     */
    private function areaRows(array $box, string $kind, int $eventLimit): array
    {
        $phenomena = LiveWarningKinds::phenomena($kind);
        $condition = 'w.vtec_significance = ? AND w.vtec_phenomenon IN ('.implode(', ', array_fill(0, count($phenomena), '?')).')';
        $eventId = 'w.id';

        if ($kind === LiveWarningKinds::WATCHES) {
            $sameWatch = 'o.vtec_phenomenon = w.vtec_phenomenon AND o.vtec_significance = w.vtec_significance'
                .' AND o.vtec_event_number = w.vtec_event_number AND o.vtec_year = w.vtec_year AND o.is_test = false';
            $condition .= " AND (w.vtec_office <> 'KWNS' OR NOT EXISTS (SELECT 1 FROM active_wwas o WHERE {$sameWatch} AND o.vtec_office <> 'KWNS'))";
            $eventId = "(SELECT MIN(o.id) FROM active_wwas o WHERE {$sameWatch} AND (o.vtec_office <> 'KWNS' OR w.vtec_office = 'KWNS'))";
        }

        $areas = $this->areas->ctes($condition, [LiveWarningKinds::significance($kind), ...$phenomena]);

        return DB::select(<<<SQL
            WITH {$areas['sql']},
            member AS MATERIALIZED (
                SELECT w.id AS active_wwa_id, {$eventId} AS event_id, w.updated_at
                FROM active_wwas w
                WHERE w.id IN (SELECT active_wwa_id FROM candidate)
            ),
            ends AS MATERIALIZED (
                SELECT m.event_id, CASE WHEN bool_or(k.expires IS NULL) THEN NULL ELSE MAX(k.expires) END AS ends
                FROM candidate k
                JOIN member m ON m.active_wwa_id = k.active_wwa_id
                GROUP BY m.event_id
            ),
            box AS (
                SELECT ST_MakeEnvelope(?::float8, ?::float8, ?::float8, ?::float8, 4269) AS geom
            ),
            inside AS MATERIALIZED (
                SELECT r.area_id, m.event_id
                FROM resolved r
                JOIN member m ON m.active_wwa_id = r.active_wwa_id, box
                WHERE r.geom && box.geom AND ST_Intersects(r.geom, box.geom)
            ),
            chosen AS (
                SELECT e.event_id, e.ends
                FROM ends e
                WHERE EXISTS (SELECT 1 FROM inside i WHERE i.event_id = e.event_id)
                ORDER BY e.ends ASC NULLS LAST, e.event_id
                LIMIT ?
            ),
            latest AS (
                SELECT DISTINCT ON (m.event_id) m.event_id, m.active_wwa_id
                FROM member m
                JOIN chosen c ON c.event_id = m.event_id
                ORDER BY m.event_id, m.updated_at DESC NULLS LAST, m.active_wwa_id DESC
            )
            SELECT i.area_id, i.event_id, l.active_wwa_id AS latest_wwa_id, c.ends AS expires,
                   ST_AsGeoJSON(ST_SimplifyPreserveTopology(r.geom, ?), 4, 0) AS geojson
            FROM chosen c
            JOIN inside i ON i.event_id = c.event_id
            JOIN latest l ON l.event_id = c.event_id
            JOIN resolved r ON r.area_id = i.area_id
            ORDER BY c.ends ASC NULLS LAST, c.event_id, i.area_id
            SQL, [
            ...$areas['bindings'],
            $box['west'], $box['south'], $box['east'], $box['north'],
            $eventLimit,
            (float) config('wxnotify.map.simplify_degrees'),
        ]);
    }
}
