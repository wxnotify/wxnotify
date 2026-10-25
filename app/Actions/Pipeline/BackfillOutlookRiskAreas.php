<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\PairOutlookAreasAfterDelay;
use App\Models\IngestedProduct;
use Illuminate\Database\Eloquent\Collection;

/**
 * PTSDY1 ("Day 1 Convective Outlook Areal Outline") and its paired
 * narrative SWODY1 bulletin are two separate NWWS deliveries for the same
 * underlying outlook, both resolving to the same SPC.OUTLOOK catalog
 * product (see ExtractProductMetadata's AWIPS crosswalk) so a subscriber
 * only ever has to pick "SPC Convective Outlook" once. If both
 * independently triggered MatchWeatherAlertRules, a Nationwide-mode rule
 * would fire twice for one real event -- SWODY1's prose never carries the
 * real per-risk-level coordinates PTSDY1 does, so this merges them into
 * one matchable event instead of two: PTSDY1's risk_level_polygons/
 * risk_levels get backfilled onto the most recent, not-yet-enriched,
 * same-product SWODY1 row from the last two hours (comfortably covering
 * the real gap between the two bulletins, which is normally minutes,
 * without risking grabbing a stale prior issuance cycle's row), which is
 * then re-matched. App\Jobs\DeliverWeatherAlert's unique lock and its own
 * DeliveryLog guard (with MatchWeatherAlertRules::dispatchOrSuppress()'s)
 * are what stop that rematch from re-delivering anything the first pass
 * (Nationwide-mode rules, right when SWODY1 itself arrived with no
 * geometry yet) already sent.
 *
 * Ordering isn't guaranteed: SPC sends both within about a second, and
 * PTSDY1 is sometimes stored a second BEFORE its SWODY1. Matching PTSDY1 on
 * its own right then delivered the outlook twice, under two product ids
 * that no delivery guard can tie together. So when no SWODY1 row is found
 * here, a real (not is_test) PTSDY1 waits: App\Jobs\PairOutlookAreasAfterDelay
 * runs pairAfterWaiting() once, `wxnotify.outlook_pairing_delay_seconds`
 * later (0 matches it on its own at once, the old behaviour). A SWODY1 that
 * arrives in the meantime is matched and delivered by its own
 * ProcessIngestedProduct as usual, and the delayed check then merges the
 * risk areas into it. Only if no SWODY1 of the same issuance ever turns up
 * is PTSDY1 matched on its own: a real alert with a plainer message body
 * (PTSDY1's own terse "line from" text) beats losing the alert entirely.
 * A test PTSDY1 (a generated scenario) keeps the immediate path; a replay
 * copy never reaches this class (ProcessIngestedProduct matches it first).
 */
class BackfillOutlookRiskAreas
{
    /**
     * The WMO header's issuance time carries only day, hour and minute, so it names an issuance only
     * within a window this wide around the outline (the same width the immediate lookup searches back).
     */
    private const SAME_ISSUANCE_WINDOW_HOURS = 2;

    public function __invoke(IngestedProduct $ptsdy1): void
    {
        $swody1 = IngestedProduct::query()
            ->where('product_id', $ptsdy1->product_id)
            ->where('id', '!=', $ptsdy1->id)
            ->whereNull('risk_level_polygons')
            ->where('created_at', '<=', $ptsdy1->created_at)
            ->where('created_at', '>=', $ptsdy1->created_at->copy()->subHours(2))
            ->latest('created_at')
            ->first();

        if ($swody1 === null) {
            $delaySeconds = self::pairingDelaySeconds();

            if ($delaySeconds > 0 && ! $ptsdy1->is_test) {
                PairOutlookAreasAfterDelay::dispatch($ptsdy1->id)->delay($delaySeconds);

                return;
            }

            app(MatchWeatherAlertRules::class)($ptsdy1);

            return;
        }

        $this->mergeInto($swody1, $ptsdy1);
    }

    /**
     * The delayed check for an outline that found no SWODY1 when it arrived. Pairs it with the SWODY1
     * of the SAME issuance, which may have arrived after it: same catalog product, a real (not test,
     * not replay) `swody1` row whose WMO issuance time (`wmo_ddhhmm`, which both bulletins of one
     * issuance share, while a later update such as the 01Z one carries its own) equals the outline's,
     * stored within two hours either side of it (the issuance time has no month). Every real pair in
     * the stored data matches this way, 0 to 32 seconds apart. An outline without an issuance time
     * cannot prove which SWODY1 is its own, so it is never paired here.
     *
     * Returns false only when no SWODY1 of that issuance exists, and the caller then matches the
     * outline on its own. When one exists but already has risk areas (this check already ran, or the
     * outline's ProcessIngestedProduct was retried after the normal immediate pairing), the outlook was
     * already delivered through it, so nothing is merged or matched again and this returns true.
     */
    public function pairAfterWaiting(IngestedProduct $ptsdy1): bool
    {
        $sameIssuance = $this->sameIssuanceOutlooks($ptsdy1);

        if ($sameIssuance->isEmpty()) {
            return false;
        }

        $unpaired = $sameIssuance
            ->filter(fn (IngestedProduct $swody1): bool => $swody1->risk_level_polygons === null)
            ->sortBy(fn (IngestedProduct $swody1): float => abs($swody1->created_at->diffInSeconds($ptsdy1->created_at)))
            ->first();

        if ($unpaired === null) {
            logger()->debug('BackfillOutlookRiskAreas: the outlook of this issuance already has its risk areas', [
                'ingested_product_id' => $ptsdy1->id,
            ]);

            return true;
        }

        $this->mergeInto($unpaired, $ptsdy1);

        return true;
    }

    public static function pairingDelaySeconds(): int
    {
        return max(0, (int) config('wxnotify.outlook_pairing_delay_seconds', 120));
    }

    /**
     * @return Collection<int, IngestedProduct>
     */
    private function sameIssuanceOutlooks(IngestedProduct $ptsdy1): Collection
    {
        if ($ptsdy1->wmo_ddhhmm === null || $ptsdy1->product_id === null) {
            return new Collection;
        }

        return IngestedProduct::query()
            ->where('product_id', $ptsdy1->product_id)
            ->where('id', '!=', $ptsdy1->id)
            ->where('awipsid', 'swody1')
            ->where('wmo_ddhhmm', $ptsdy1->wmo_ddhhmm)
            ->where('is_test', false)
            ->whereNull('replay_of_id')
            ->whereBetween('created_at', [
                $ptsdy1->created_at->copy()->subHours(self::SAME_ISSUANCE_WINDOW_HOURS),
                $ptsdy1->created_at->copy()->addHours(self::SAME_ISSUANCE_WINDOW_HOURS),
            ])
            ->get();
    }

    private function mergeInto(IngestedProduct $swody1, IngestedProduct $ptsdy1): void
    {
        $swody1->forceFill([
            'risk_level_polygons' => $ptsdy1->risk_level_polygons,
            'risk_levels' => $ptsdy1->risk_levels,
        ])->save();

        app(MatchWeatherAlertRules::class)($swody1->fresh());
    }
}
