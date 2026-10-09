<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\DB;

/**
 * Fills ingested_product_segments for a VTEC bulletin: what MatchWeatherAlertRules matches
 * instead of the bulletin's first zone block and first VTEC line. Idempotent: it parses first,
 * then replaces the bulletin's segments in one transaction, so a parse failure leaves the old
 * segments untouched.
 *
 * A text bulletin is split by ParseBulletinSegments. A CAP message always gets exactly one
 * segment, copied from its already-extracted columns (it is one zone's message of an event). A
 * text bulletin whose sections cannot be found falls back to the same single segment, so an odd
 * bulletin never loses its alert. A product with no vtec_office (AFD, forecasts) gets none.
 */
class BuildBulletinSegments
{
    public function __construct(private readonly ParseBulletinSegments $parseSegments) {}

    public function __invoke(IngestedProduct $bulletin): void
    {
        if ($bulletin->vtec_office === null) {
            return;
        }

        $rows = $bulletin->format === 'cap' ? [] : ($this->parseSegments)((string) $bulletin->text);

        if ($rows === []) {
            $rows = [$this->fromBulletin($bulletin)];
        }

        if ($bulletin->vtec_year !== null) {
            $rows = array_map(fn (array $row): array => [...$row, 'vtec_year' => $bulletin->vtec_year], $rows);
        }

        DB::transaction(function () use ($bulletin, $rows): void {
            $bulletin->segments()->delete();

            foreach (array_values($rows) as $position => $row) {
                $bulletin->segments()->create([...$row, 'position' => $position]);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromBulletin(IngestedProduct $bulletin): array
    {
        return [
            'vtec_office' => $bulletin->vtec_office,
            'vtec_phenomenon' => $bulletin->vtec_phenomenon,
            'vtec_significance' => $bulletin->vtec_significance,
            'vtec_event_number' => $bulletin->vtec_event_number,
            'vtec_year' => $bulletin->vtec_year,
            'vtec_action' => $bulletin->vtec_action,
            'vtec_begin' => $bulletin->vtec_begin,
            'vtec_end' => $bulletin->vtec_end,
            'product_id' => $bulletin->product_id,
            'ugc_codes' => $bulletin->ugc_codes,
            'polygon' => $bulletin->polygon,
        ];
    }
}
