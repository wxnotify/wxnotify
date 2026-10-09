<?php

namespace App\Actions\Pipeline;

use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Splits a VTEC text bulletin into its events. A bulletin is a list of sections separated by
 * "$$"; each section has its own zone line, one or more VTEC lines (one per event it covers) and
 * its own LAT...LON polygon. One entry comes back per (section x VTEC line), in bulletin order,
 * so a section with two VTEC lines gives two entries that share the section's zones and polygon.
 * A section without a VTEC line gives none. A section whose zone line cannot be read still gives
 * its entries, with null zones.
 *
 * The catalog product of an entry is resolved from its own VTEC phenomenon and significance
 * ("HU.A"), exactly as ExtractProductMetadata does for a bulletin's first VTEC line; null when the
 * catalog has no such event (Storm Surge, Rip Current and others).
 */
class ParseBulletinSegments
{
    /** @var array<string, ?int> */
    private array $productIds = [];

    public function __construct(
        private readonly ParseVtecString $parseVtec,
        private readonly ParseUgcLine $parseUgc,
        private readonly ParseLatLonPolygon $parsePolygon,
    ) {}

    /**
     * @return list<array{vtec_office: string, vtec_phenomenon: string, vtec_significance: string, vtec_event_number: string, vtec_year: int, vtec_action: string, vtec_begin: ?Carbon, vtec_end: ?Carbon, product_id: ?int, ugc_codes: ?list<string>, polygon: ?array<int, array{lat: float, lon: float}>}>
     */
    public function __invoke(string $text): array
    {
        $segments = [];

        foreach (preg_split('/^\$\$\s*$/m', $text) as $section) {
            $events = $this->parseVtec->all($section);

            if ($events === []) {
                continue;
            }

            $ugcCodes = ($this->parseUgc)($section);
            $polygon = ($this->parsePolygon)($section);

            foreach ($events as $event) {
                $segments[] = [
                    'vtec_office' => $event['office'],
                    'vtec_phenomenon' => $event['phenomenon'],
                    'vtec_significance' => $event['significance'],
                    'vtec_event_number' => $event['event_number'],
                    'vtec_year' => $event['year'],
                    'vtec_action' => $event['action'],
                    'vtec_begin' => $event['begin'],
                    'vtec_end' => $event['end'],
                    'product_id' => $this->productIdFor($event['phenomenon'], $event['significance']),
                    'ugc_codes' => $ugcCodes,
                    'polygon' => $polygon,
                ];
            }
        }

        return $segments;
    }

    private function productIdFor(string $phenomenon, string $significance): ?int
    {
        $code = "{$phenomenon}.{$significance}";

        if (! array_key_exists($code, $this->productIds)) {
            $this->productIds[$code] = Product::where('code', $code)->value('id');
        }

        return $this->productIds[$code];
    }
}
