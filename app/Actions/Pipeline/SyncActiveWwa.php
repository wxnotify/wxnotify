<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Updates the "current state" active_wwas rows for a VTEC-class IngestedProduct -- one event per
 * VTEC line of every section of the bulletin, each applied through ApplySegmentToCoverage so
 * its zones accumulate and end one by one -- and decides whether this ingestion should trigger
 * a fresh match-and-dispatch pass. Text rows always dispatch -- this is what preserves today's
 * behavior of notifying on every issuance including continuations (SVS/FFS/etc, which resolve to
 * the same product_id as their parent warning). CAP rows only dispatch when their paired text row
 * can't be found, since the normal case is CAP arriving a few seconds after text already dispatched.
 *
 * A bulletin without stored segments (a factory row, or one BuildBulletinSegments failed on) is
 * applied through one unsaved segment built from its own columns, which is what every bulletin
 * was before segments existed.
 *
 * Both syncFromText() and syncFromCap() write motion_* fields when their respective source
 * carries one (a text row's own TIME...MOT...LOC line, or CAP's eventMotionDescription
 * parameter), onto the bulletin's FIRST event only -- text is what actually populates it in the
 * common case, since dispatch (and therefore anything consuming these columns, e.g. the Custom
 * Alert Templates Motion section) usually fires off the text row before CAP has a chance to
 * arrive at all.
 */
class SyncActiveWwa
{
    public function __invoke(IngestedProduct $product): void
    {
        if ($product->format === 'cap') {
            $this->syncFromCap($product);

            return;
        }

        $this->syncFromText($product);
    }

    private function syncFromText(IngestedProduct $product): void
    {
        try {
            DB::transaction(function () use ($product): void {
                $motion = app(ParseTextMotionDescription::class)($product->text, $product->received_at);

                foreach ($this->segmentsOf($product) as $index => $segment) {
                    app(ApplySegmentToCoverage::class)($product, $segment, [
                        'source' => 'text',
                        'latest_ingested_product_id' => $product->id,
                        ...($index === 0 ? $motion : []),
                    ]);
                }
            });
        } catch (Throwable $e) {
            logger()->error('Failed to sync active_wwas for a text-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        app(MatchWeatherAlertRules::class)($product);
    }

    private function syncFromCap(IngestedProduct $product): void
    {
        $pairedText = null;
        $lookupFailed = false;

        try {
            $pairedText = $this->findPairedTextProduct($product);

            DB::transaction(function () use ($product, $pairedText): void {
                $motion = $this->parseMotion($product->event_motion_description);

                foreach ($this->segmentsOf($product) as $index => $segment) {
                    app(ApplySegmentToCoverage::class)($product, $segment, [
                        'headline' => $product->headline,
                        'source' => 'cap',
                        'latest_ingested_product_id' => $pairedText?->id ?? $product->id,
                        ...($index === 0 ? $motion : []),
                    ]);
                }
            });
        } catch (Throwable $e) {
            $lookupFailed = true;
            logger()->error('Failed to sync active_wwas for a CAP-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($pairedText === null || $lookupFailed) {
            app(MatchWeatherAlertRules::class)($product);
        }
    }

    /**
     * The bulletin's stored segments in bulletin order, or -- for a bulletin that has none -- one
     * unsaved segment carrying its own columns.
     *
     * @return Collection<int, IngestedProductSegment>
     */
    private function segmentsOf(IngestedProduct $product): Collection
    {
        $segments = $product->segments()->get();

        if ($segments->isNotEmpty()) {
            return $segments;
        }

        return collect([new IngestedProductSegment([
            'position' => 0,
            'vtec_office' => $product->vtec_office,
            'vtec_phenomenon' => $product->vtec_phenomenon,
            'vtec_significance' => $product->vtec_significance,
            'vtec_event_number' => $product->vtec_event_number,
            'vtec_year' => $product->vtec_year,
            'vtec_action' => $product->vtec_action,
            'vtec_begin' => $product->vtec_begin,
            'vtec_end' => $product->vtec_end,
            'product_id' => $product->product_id,
            'ugc_codes' => $product->ugc_codes,
            'polygon' => $product->polygon,
        ])]);
    }

    /**
     * Correlates a CAP row to the specific text issuance it cross-references,
     * via CAP's own AWIPSidentifier + WMOidentifier parameters (which decode
     * to exactly the awipsid/ttaaii/cccc/wmo_ddhhmm columns a text row
     * already stores).
     */
    private function findPairedTextProduct(IngestedProduct $capProduct): ?IngestedProduct
    {
        if ($capProduct->awips_identifier === null || $capProduct->wmo_identifier === null) {
            return null;
        }

        $parts = explode(' ', $capProduct->wmo_identifier);
        if (count($parts) !== 3) {
            return null;
        }

        [$ttaaii, $cccc, $ddhhmm] = $parts;

        return IngestedProduct::query()
            ->where('format', 'text')
            ->where('awipsid', strtolower($capProduct->awips_identifier))
            ->where('cccc', strtolower($cccc))
            ->where('ttaaii', strtolower($ttaaii))
            ->where('wmo_ddhhmm', $ddhhmm)
            ->where('received_at', '>=', $capProduct->received_at->copy()->subMinutes(10))
            ->where('received_at', '<=', $capProduct->received_at)
            ->latest('received_at')
            ->first();
    }

    /**
     * Parses CAP's eventMotionDescription parameter, e.g.
     * "2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18",
     * into active_wwas's discrete motion_* columns. Built from a single real
     * confirmed example -- if a real product's format ever varies, this
     * needs revisiting, not guessed at further.
     *
     * @return array{motion_observed_at?: Carbon, motion_direction_degrees?: int, motion_speed_kt?: int, motion_reference_lat?: float, motion_reference_lon?: float}
     */
    private function parseMotion(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (! preg_match('/^(?<observed>[\d\-T:+]+)\.\.\.\w+\.\.\.(?<deg>\d+)DEG\.\.\.(?<kt>\d+)KT\.\.\.(?<lat>-?\d+\.\d+),(?<lon>-?\d+\.\d+)$/', $raw, $m)) {
            return [];
        }

        return [
            'motion_observed_at' => Carbon::parse($m['observed']),
            'motion_direction_degrees' => (int) $m['deg'],
            'motion_speed_kt' => (int) $m['kt'],
            'motion_reference_lat' => (float) $m['lat'],
            'motion_reference_lon' => (float) $m['lon'],
        ];
    }
}
