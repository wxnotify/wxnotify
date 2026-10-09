<?php

namespace App\Actions\Pipeline;

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use Illuminate\Support\Facades\DB;

/**
 * Applies one segment (one event of one section of a bulletin) to its active event: finds or
 * creates the active_wwas row by the segment's VTEC key, sets or ends the segment's zones in
 * active_wwa_areas by its VTEC action, then derives the event row's status, start and end from
 * its zones. It never matches alert rules and never sends anything: SyncActiveWwa calls it for
 * every segment of a live bulletin, and active-wwas:rebuild replays stored segments through it.
 *
 * NEW, CON, EXT, EXA, EXB, COR and ROU set the segment's zones Active; CAN and UPG (an upgrade
 * ends the old event) set them Cancelled; EXP sets them Expired. Other zones of the event are
 * untouched, which is the point: one zone's cancel must not end the whole event. A zone row
 * already written by a newer bulletin (its applied_at is later than this bulletin's received_at)
 * is left alone.
 */
class ApplySegmentToCoverage
{
    private const ACTIVE = 'Active';

    private const CANCELLED = 'Cancelled';

    private const EXPIRED = 'Expired';

    /**
     * @param  array<string, mixed>  $eventAttributes  extra active_wwas columns to write: source, headline, latest_ingested_product_id, motion_*
     */
    public function __invoke(IngestedProduct $bulletin, IngestedProductSegment $segment, array $eventAttributes = []): ActiveWwa
    {
        $wwa = ActiveWwa::updateOrCreate(
            ActiveWwa::vtecKeyFor($segment),
            [
                'product_id' => $segment->product_id,
                // A freshly created (not ->fresh()'d) IngestedProduct has is_test === null in memory --
                // Eloquent doesn't reload the row after insert, so the column's `false` default never
                // reaches the attribute. active_wwas.is_test is NOT NULL, so coalesce rather than let the
                // insert throw (SyncActiveWwa would swallow it and drop the whole event).
                'is_test' => $bulletin->is_test ?? false,
                ...($segment->ugc_codes !== null ? ['ugc_codes' => $segment->ugc_codes] : []),
                ...$eventAttributes,
            ],
        );

        $this->applyToZones($wwa, $bulletin, $segment);

        if ($segment->polygon !== null) {
            DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$this->polygonToWkt($segment->polygon), $wwa->id]);
        }

        $this->deriveEvent($wwa, $segment);

        return $wwa;
    }

    private function applyToZones(ActiveWwa $wwa, IngestedProduct $bulletin, IngestedProductSegment $segment): void
    {
        // A CAP message may repeat a geocode; one upsert cannot touch the same row twice.
        $zones = array_values(array_unique($segment->ugc_codes ?? []));

        if ($zones === []) {
            return;
        }

        $status = $this->statusFor($segment->vtec_action);
        $appliedAt = $bulletin->received_at ?? now();
        $existing = $wwa->areas()->whereIn('ugc_code', $zones)->get()->keyBy('ugc_code');

        $zones = array_values(array_filter($zones, fn (string $zone): bool => ! ($existing->get($zone)?->applied_at?->greaterThan($appliedAt) ?? false)));

        if ($zones === []) {
            return;
        }

        $rows = array_map(fn (string $zone): array => [
            'active_wwa_id' => $wwa->id,
            'ugc_code' => $zone,
            'status' => $status,
            'effective' => $segment->vtec_begin,
            'expires' => $segment->vtec_end,
            'latest_segment_id' => $segment->exists ? $segment->id : null,
            'applied_at' => $appliedAt,
        ], $zones);

        // A time the segment does not carry (a CON's "until further notice" end is null) never clears one already known.
        ActiveWwaArea::upsert($rows, ['active_wwa_id', 'ugc_code'], [
            'status', 'latest_segment_id', 'applied_at',
            ...($segment->vtec_begin !== null ? ['effective'] : []),
            ...($segment->vtec_end !== null ? ['expires'] : []),
        ]);

        if ($segment->polygon !== null && $status === self::ACTIVE) {
            $this->setPolygon($wwa, $zones, $segment->polygon, keepExisting: $bulletin->format === 'cap');
        }
    }

    /**
     * A text segment's polygon is the section's own and replaces the zone's; a CAP message's only
     * fills a zone that has none. A segment without a polygon never calls this, so a polygon-less
     * continuation statement keeps the polygon already known.
     *
     * @param  list<string>  $zones
     * @param  array<int, array{lat: float, lon: float}>  $polygon
     */
    private function setPolygon(ActiveWwa $wwa, array $zones, array $polygon, bool $keepExisting): void
    {
        $geometry = 'ST_SetSRID(ST_GeomFromText(?), 4269)';
        $set = $keepExisting ? "COALESCE(polygon, {$geometry})" : $geometry;
        $placeholders = implode(', ', array_fill(0, count($zones), '?'));

        DB::statement(
            "UPDATE active_wwa_areas SET polygon = {$set} WHERE active_wwa_id = ? AND ugc_code IN ({$placeholders})",
            [$this->polygonToWkt($polygon), $wwa->id, ...$zones],
        );
    }

    /**
     * The event row follows its zones: Active while any zone is, else the status of the zone most
     * recently ended; its end is the latest end of the active zones, or none if any active zone has
     * none ("until further notice"); its start is the earliest. An event that has no zone rows
     * (a bulletin without a readable zone line) takes the action's status and times, as it always did.
     */
    private function deriveEvent(ActiveWwa $wwa, IngestedProductSegment $segment): void
    {
        $areas = $wwa->areas()->get();

        if ($areas->isEmpty()) {
            $wwa->update([
                'status' => $this->statusFor($segment->vtec_action),
                ...($segment->vtec_begin !== null ? ['effective' => $segment->vtec_begin] : []),
                ...($segment->vtec_end !== null ? ['expires' => $segment->vtec_end] : []),
            ]);
        } else {
            $active = $areas->where('status', self::ACTIVE);
            $considered = $active->isNotEmpty() ? $active : $areas;
            $lastEnded = $areas->sortByDesc(fn (ActiveWwaArea $area): array => [$area->applied_at?->getTimestamp() ?? 0, $area->id])->first();
            $earliestStart = $areas->pluck('effective')->filter()->min();

            $wwa->update([
                'status' => $active->isNotEmpty() ? self::ACTIVE : $lastEnded->status,
                'expires' => $considered->contains(fn (ActiveWwaArea $area): bool => $area->expires === null) ? null : $considered->max('expires'),
                ...($earliestStart !== null ? ['effective' => $earliestStart] : []),
            ]);
        }

        // NWS often lets an event lapse with no EXP product ever issued: once the recorded end has passed, it is over.
        if ($wwa->status === self::ACTIVE && $wwa->expires !== null && $wwa->expires->isPast()) {
            $wwa->update(['status' => self::EXPIRED]);
        }
    }

    private function statusFor(?string $vtecAction): string
    {
        return match ($vtecAction) {
            'CAN', 'UPG' => self::CANCELLED,
            'EXP' => self::EXPIRED,
            default => self::ACTIVE,
        };
    }

    /**
     * A PostGIS POLYGON ring needs at least 4 coordinate pairs with the
     * first repeated as the last. Real LAT...LON/CAP polygons always carry
     * well more than that, but degenerate input (as low as a single point)
     * is padded by repeating the last point rather than rejected -- this
     * mirrors ST_GeomFromText's own tolerance for a zero-area ring (a ring
     * of identical points is accepted), confirmed directly against this
     * database.
     *
     * @param  array<int, array{lat: float, lon: float}>  $points
     */
    private function polygonToWkt(array $points): string
    {
        $coords = collect($points)->map(fn (array $p): string => "{$p['lon']} {$p['lat']}");

        if ($coords->first() !== $coords->last()) {
            $coords->push($coords->first());
        }

        while ($coords->count() < 4) {
            $coords->push($coords->last());
        }

        return 'POLYGON(('.$coords->join(', ').'))';
    }
}
