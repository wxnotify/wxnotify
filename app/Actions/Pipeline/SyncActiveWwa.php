<?php

namespace App\Actions\Pipeline;

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Upserts the "current state" active_wwas row for a VTEC-class IngestedProduct
 * and decides whether this ingestion should trigger a fresh match-and-dispatch
 * pass. Text rows always dispatch -- this is what preserves today's behavior
 * of notifying on every issuance including continuations (SVS/FFS/etc, which
 * resolve to the same product_id as their parent warning). CAP rows only
 * dispatch when their paired text row can't be found, since the normal case
 * is CAP arriving a few seconds after text already dispatched.
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
            $wwa = ActiveWwa::updateOrCreate(
                $this->vtecKey($product),
                [
                    'product_id' => $product->product_id,
                    'status' => $this->resolveStatus($product->vtec_action),
                    'ugc_codes' => $product->ugc_codes,
                    'effective' => $product->vtec_begin,
                    'expires' => $product->vtec_end,
                    'latest_ingested_product_id' => $product->id,
                    'source' => 'text',
                ],
            );

            if ($product->polygon !== null) {
                $wkt = $this->polygonToWkt($product->polygon);
                DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
            }
        } catch (\Throwable $e) {
            logger()->error('Failed to sync active_wwas for a text-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        app(MatchWeatherAlertRules::class)($product);
    }

    private function syncFromCap(IngestedProduct $product): void
    {
        $pairedText = $this->findPairedTextProduct($product);

        try {
            $wwa = ActiveWwa::updateOrCreate(
                $this->vtecKey($product),
                [
                    'product_id' => $product->product_id,
                    'status' => $this->resolveStatus($product->vtec_action),
                    'ugc_codes' => $product->ugc_codes,
                    'effective' => $product->vtec_begin,
                    'expires' => $product->vtec_end,
                    'headline' => $product->headline,
                    'source' => 'cap',
                    'latest_ingested_product_id' => $pairedText?->id ?? $product->id,
                    ...$this->parseMotion($product->event_motion_description),
                ],
            );

            if ($product->polygon !== null) {
                $wkt = $this->polygonToWkt($product->polygon);
                DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', [$wkt, $wwa->id]);
            }
        } catch (\Throwable $e) {
            logger()->error('Failed to sync active_wwas for a CAP-sourced VTEC product', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }

        if ($pairedText === null) {
            app(MatchWeatherAlertRules::class)($product);
        }
    }

    /**
     * @return array{vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int}
     */
    private function vtecKey(IngestedProduct $product): array
    {
        return [
            'vtec_office' => $product->vtec_office,
            'vtec_phenomenon' => $product->vtec_phenomenon,
            'vtec_significance' => $product->vtec_significance,
            'vtec_event_number' => $product->vtec_event_number,
            'vtec_year' => $product->vtec_year,
        ];
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
