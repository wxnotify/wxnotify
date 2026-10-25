<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\PairOutlookAreasAfterDelay;
use App\Models\IngestedProduct;
use Carbon\CarbonInterface;
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
 * risk_levels get backfilled onto its SWODY1, which is then re-matched.
 * App\Jobs\DeliverWeatherAlert's unique lock and its own DeliveryLog guard
 * (with MatchWeatherAlertRules::dispatchOrSuppress()'s) are what stop that
 * rematch from re-delivering anything the first pass (Nationwide-mode
 * rules, right when SWODY1 itself arrived with no geometry yet) already sent.
 *
 * Its SWODY1 is found by one shared filter (outlookTexts()): the same
 * catalog product, a real `swody1` row (not is_test, no replay_of_id), the
 * same WMO header issuance time (headerIssuance()), not yet carrying risk
 * areas, stored within a window around the outline. The WMO header time is
 * the key because both bulletins of one issuance carry it whatever feed
 * brought them, while each later update or correction carries its own; the
 * wmo_ddhhmm column is not, since an NWWS row stores the XMPP issue time
 * there (1-3 minutes earlier) and an EMWIN row the header's. A row with no
 * or a malformed header key is never paired. When several qualify the one
 * nearest in time to the outline wins, and of equally near rows the one
 * stored last (highest id).
 *
 * Ordering isn't guaranteed: SPC sends both within about a second, and
 * PTSDY1 is sometimes stored a second BEFORE its SWODY1. Matching PTSDY1 on
 * its own right then delivered the outlook twice, under two product ids
 * that no delivery guard can tie together. So, with a pairing delay
 * (`wxnotify.outlook_pairing_delay_seconds`, 0 = the old immediate
 * standalone match):
 *  - the SWODY1, when ProcessIngestedProduct gets to it, first takes the
 *    risk areas of a waiting same-issuance outline stored up to the delay
 *    before it (mergeWaitingOutline()), so its one match fires Nationwide,
 *    location and polygon rules in one pass. This is the one change to the
 *    SWODY1-first order: a SWODY1 processed after its outline was stored is
 *    matched once with the areas instead of a Nationwide pass plus the
 *    re-match;
 *  - an outline that finds no SWODY1 dispatches App\Jobs\PairOutlookAreasAfterDelay
 *    for the delay, unless its SWODY1 already took the areas (then nothing
 *    is left to do). That job's pairAfterWaiting() merges into a SWODY1 that
 *    arrived meanwhile without taking them, and otherwise matches PTSDY1 on
 *    its own: a real alert with a plainer message body (PTSDY1's own terse
 *    "line from" text) beats losing the alert entirely. With the reverse
 *    merge, the delay only bounds that orphan-outline fallback.
 * A test or replay PTSDY1 is matched on its own at once (replays never
 * reach this class: ProcessIngestedProduct matches them first).
 */
class BackfillOutlookRiskAreas
{
    /**
     * The WMO header's issuance time carries only day, hour and minute, so it names an issuance only
     * within a window this wide around the outline (the width the immediate lookup always searched back).
     */
    private const SAME_ISSUANCE_WINDOW_HOURS = 2;

    public function __invoke(IngestedProduct $ptsdy1): void
    {
        if ($ptsdy1->is_test || $ptsdy1->replay_of_id !== null) {
            app(MatchWeatherAlertRules::class)($ptsdy1);

            return;
        }

        $swody1 = $this->nearest(
            $this->outlookTexts($ptsdy1, $ptsdy1->created_at->copy()->subHours(self::SAME_ISSUANCE_WINDOW_HOURS), $ptsdy1->created_at)
                ->filter(fn (IngestedProduct $row): bool => $row->risk_level_polygons === null),
            $ptsdy1,
        );

        if ($swody1 !== null) {
            $this->mergeInto($swody1, $ptsdy1);

            return;
        }

        $delaySeconds = self::pairingDelaySeconds();

        if ($delaySeconds > 0 && $ptsdy1->product_id !== null && self::headerIssuance($ptsdy1) !== null) {
            if ($this->sameIssuanceOutlookTexts($ptsdy1)->contains(fn (IngestedProduct $row): bool => $row->risk_level_polygons !== null)) {
                logger()->debug('BackfillOutlookRiskAreas: the outlook of this issuance already has its risk areas', [
                    'ingested_product_id' => $ptsdy1->id,
                ]);

                return;
            }

            PairOutlookAreasAfterDelay::dispatch($ptsdy1->id)->delay($delaySeconds);

            return;
        }

        app(MatchWeatherAlertRules::class)($ptsdy1);
    }

    /**
     * The delayed check for an outline that found no SWODY1 when it arrived: merges into the nearest
     * same-issuance SWODY1 stored within two hours either side of it that has no risk areas yet, and
     * re-matches it. Returns false only when no SWODY1 of that issuance exists, and the caller then
     * matches the outline on its own. When one exists but already has risk areas (it took them through
     * mergeWaitingOutline(), or this check already ran), the outlook was already delivered through it,
     * so nothing is merged or matched again and this returns true.
     */
    public function pairAfterWaiting(IngestedProduct $ptsdy1): bool
    {
        $sameIssuance = $this->sameIssuanceOutlookTexts($ptsdy1);

        if ($sameIssuance->isEmpty()) {
            return false;
        }

        $unpaired = $this->nearest($sameIssuance->filter(fn (IngestedProduct $row): bool => $row->risk_level_polygons === null), $ptsdy1);

        if ($unpaired === null) {
            logger()->debug('BackfillOutlookRiskAreas: the outlook of this issuance already has its risk areas', [
                'ingested_product_id' => $ptsdy1->id,
            ]);

            return true;
        }

        $this->mergeInto($unpaired, $ptsdy1);

        return true;
    }

    /**
     * Called by ProcessIngestedProduct for a SWODY1 just before it is matched: gives it the risk areas of
     * the newest real same-issuance outline stored up to the pairing delay before it, so the one match
     * already carries them. The window is the delay because an older outline may already have fallen
     * back to its own standalone match, and merging it would send location rules a second copy. The
     * write only lands on a row still without risk areas, so two runs merge once. Never matches.
     */
    public function mergeWaitingOutline(IngestedProduct $swody1): void
    {
        $delaySeconds = self::pairingDelaySeconds();
        $issuance = self::headerIssuance($swody1);

        if ($delaySeconds <= 0 || $swody1->awipsid !== 'swody1' || $swody1->is_test || $swody1->replay_of_id !== null
            || $swody1->risk_level_polygons !== null || $swody1->product_id === null || $issuance === null) {
            return;
        }

        $ptsdy1 = IngestedProduct::query()
            ->where('product_id', $swody1->product_id)
            ->where('awipsid', 'ptsdy1')
            ->where('is_test', false)
            ->whereNull('replay_of_id')
            ->whereNotNull('risk_level_polygons')
            ->whereBetween('created_at', [$swody1->created_at->copy()->subSeconds($delaySeconds), $swody1->created_at])
            ->get()
            ->filter(fn (IngestedProduct $row): bool => self::headerIssuance($row) === $issuance)
            ->sortByDesc(fn (IngestedProduct $row): array => [$row->created_at->getTimestamp(), $row->id])
            ->first();

        if ($ptsdy1 === null) {
            return;
        }

        IngestedProduct::query()
            ->whereKey($swody1->id)
            ->whereNull('risk_level_polygons')
            ->update([
                'risk_level_polygons' => json_encode($ptsdy1->risk_level_polygons),
                'risk_levels' => $ptsdy1->risk_levels === null ? null : json_encode($ptsdy1->risk_levels),
                'updated_at' => now(),
            ]);

        $swody1->refresh();
    }

    public static function pairingDelaySeconds(): int
    {
        return max(0, (int) config('wxnotify.outlook_pairing_delay_seconds', 60));
    }

    /**
     * The WMO header's DDHHMM, the third token of `wmo_header_key` (e.g. "acus01 kwns 250109 swody1"),
     * or null for a row without one (a replay copy) or with a malformed one.
     */
    public static function headerIssuance(IngestedProduct $row): ?string
    {
        $issuance = explode(' ', (string) $row->wmo_header_key)[2] ?? null;

        return $issuance !== null && preg_match('/^\d{6}$/', $issuance) === 1 ? $issuance : null;
    }

    /**
     * @return Collection<int, IngestedProduct>
     */
    private function sameIssuanceOutlookTexts(IngestedProduct $ptsdy1): Collection
    {
        return $this->outlookTexts(
            $ptsdy1,
            $ptsdy1->created_at->copy()->subHours(self::SAME_ISSUANCE_WINDOW_HOURS),
            $ptsdy1->created_at->copy()->addHours(self::SAME_ISSUANCE_WINDOW_HOURS),
        );
    }

    /**
     * The real SWODY1 rows of the outline's issuance stored between the two times, with or without risk
     * areas (each caller decides): the one filter the immediate lookup and the delayed check share.
     *
     * @return Collection<int, IngestedProduct>
     */
    private function outlookTexts(IngestedProduct $ptsdy1, CarbonInterface $from, CarbonInterface $to): Collection
    {
        $issuance = self::headerIssuance($ptsdy1);

        if ($issuance === null || $ptsdy1->product_id === null) {
            return new Collection;
        }

        return IngestedProduct::query()
            ->where('product_id', $ptsdy1->product_id)
            ->where('id', '!=', $ptsdy1->id)
            ->where('awipsid', 'swody1')
            ->where('is_test', false)
            ->whereNull('replay_of_id')
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->filter(fn (IngestedProduct $row): bool => self::headerIssuance($row) === $issuance)
            ->values();
    }

    /**
     * Nearest in time to the outline wins; of equally near rows, the one stored last (highest id).
     *
     * @param  Collection<int, IngestedProduct>  $rows
     */
    private function nearest(Collection $rows, IngestedProduct $ptsdy1): ?IngestedProduct
    {
        return $rows
            ->sortBy([
                fn (IngestedProduct $a, IngestedProduct $b): int => abs($a->created_at->getTimestamp() - $ptsdy1->created_at->getTimestamp())
                    <=> abs($b->created_at->getTimestamp() - $ptsdy1->created_at->getTimestamp()),
                fn (IngestedProduct $a, IngestedProduct $b): int => $b->id <=> $a->id,
            ])
            ->first();
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
