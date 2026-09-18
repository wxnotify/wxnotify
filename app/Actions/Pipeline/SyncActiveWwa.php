<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Upserts the "current state" active_wwas row for a VTEC-class IngestedProduct
 * and decides whether this ingestion should trigger a fresh match-and-dispatch
 * pass. Text rows always dispatch -- this is what preserves today's behavior
 * of notifying on every issuance including continuations (SVS/FFS/etc, which
 * resolve to the same product_id as their parent warning). CAP rows only
 * dispatch when their paired text row can't be found, since the normal case
 * is CAP arriving a few seconds after text already dispatched.
 *
 * Both syncFromText() and syncFromCap() write motion_* fields when their
 * respective source carries one (a text row's own TIME...MOT...LOC line, or
 * CAP's eventMotionDescription parameter) -- text is what actually populates
 * it in the common case, since dispatch (and therefore anything consuming
 * these columns, e.g. the Custom Alert Templates Motion section) usually
 * fires off the text row before CAP has a chance to arrive at all.
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
                $wwa = ActiveWwa::updateOrCreate(
                    ActiveWwa::vtecKeyFor($product),
                    [
                        'product_id' => $product->product_id,
                        'status' => $this->resolveStatus($product->vtec_action),
                        'is_test' => $this->isTest($product),
                        'latest_ingested_product_id' => $product->id,
                        'source' => 'text',
                        ...($product->ugc_codes !== null ? ['ugc_codes' => $product->ugc_codes] : []),
                        ...($product->vtec_begin !== null ? ['effective' => $product->vtec_begin] : []),
                        ...($product->vtec_end !== null ? ['expires' => $product->vtec_end] : []),
                        ...app(ParseTextMotionDescription::class)($product->text, $product->received_at),
                    ],
                );

                $this->expireIfPast($wwa);

                if ($product->polygon !== null) {
                    $wkt = $this->polygonToWkt($product->polygon);
                    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
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
                $wwa = ActiveWwa::updateOrCreate(
                    ActiveWwa::vtecKeyFor($product),
                    [
                        'product_id' => $product->product_id,
                        'status' => $this->resolveStatus($product->vtec_action),
                        'is_test' => $this->isTest($product),
                        'headline' => $product->headline,
                        'source' => 'cap',
                        'latest_ingested_product_id' => $pairedText?->id ?? $product->id,
                        ...($product->ugc_codes !== null ? ['ugc_codes' => $product->ugc_codes] : []),
                        ...($product->vtec_begin !== null ? ['effective' => $product->vtec_begin] : []),
                        ...($product->vtec_end !== null ? ['expires' => $product->vtec_end] : []),
                        ...$this->parseMotion($product->event_motion_description),
                    ],
                );

                $this->expireIfPast($wwa);

                if ($product->polygon !== null) {
                    $wkt = $this->polygonToWkt($product->polygon);
                    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
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
     * A freshly-created (not `->fresh()`'d) IngestedProduct instance has
     * `is_test === null` in memory -- Eloquent doesn't reload the row after
     * insert, so the `is_test` column's `false` database default never
     * makes it into the in-memory attribute, even though every real row
     * does end up `false` in the database. Coalescing here prevents an
     * explicit NULL write against active_wwas.is_test, which is NOT NULL --
     * that insert would throw, and since it happens inside this action's
     * try/catch, the exception would be silently logged and swallowed,
     * dropping the whole active_wwas row for every real (non-test) product.
     */
    private function isTest(IngestedProduct $product): bool
    {
        return $product->is_test ?? false;
    }

    private function resolveStatus(?string $vtecAction): string
    {
        return match ($vtecAction) {
            'CAN' => 'Cancelled',
            'EXP' => 'Expired',
            default => 'Active',
        };
    }

    /**
     * Demotes an already-Active row to Expired once its currently-recorded
     * expires timestamp has passed -- covers the common case where NWS lets
     * a warning lapse with no explicit EXP product ever issued. Only ever
     * fires against whatever expires value is currently on record (which
     * might have been set by an EARLIER issuance, not necessarily this
     * one), since a later touch to the row (e.g. a CAP enrichment, or a
     * continuation that doesn't itself carry a fresh end time) is exactly
     * the moment this check should re-run.
     */
    private function expireIfPast(ActiveWwa $wwa): void
    {
        if ($wwa->status === 'Active' && $wwa->expires !== null && $wwa->expires->isPast()) {
            $wwa->update(['status' => 'Expired']);
        }
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
