<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;

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
 * then re-matched. MatchWeatherAlertRules::dispatchOrSuppress()'s own
 * DeliveryLog guard is what stops that rematch from re-delivering
 * anything the first pass (Nationwide-mode rules, right when SWODY1 itself
 * arrived with no geometry yet) already sent.
 *
 * If no such row exists -- ordering isn't guaranteed; PTSDY1 can arrive
 * first, or SWODY1 might not resolve a product_id at all -- $ptsdy1 is left
 * to stand as its own independently-matched row: a real alert with a
 * plainer message body (PTSDY1's own terse "line from" text) beats losing
 * the alert entirely.
 */
class BackfillOutlookRiskAreas
{
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
            app(MatchWeatherAlertRules::class)($ptsdy1);

            return;
        }

        $swody1->forceFill([
            'risk_level_polygons' => $ptsdy1->risk_level_polygons,
            'risk_levels' => $ptsdy1->risk_levels,
        ])->save();

        app(MatchWeatherAlertRules::class)($swody1->fresh());
    }
}
