<?php

namespace App\Actions\Pipeline;

use App\Models\Product;
use Illuminate\Support\Carbon;
use SimpleXMLElement;

/**
 * Parses a CAP (Common Alerting Protocol) XML product body -- the payload
 * NWS broadcasts on NWWS alongside every VTEC-class text product, under an
 * awipsid starting with "CAP" (see IngestNwwsProductController for the
 * detection check).
 *
 * Mirrors ExtractProductMetadata's output shape wherever the two overlap
 * (product_id, ugc_codes, polygon, the shared vtec_* fields) so both
 * extractors feed IngestedProduct identically. CAP does NOT split
 * hazard/source/impact out of <description> -- that narrative text arrives
 * exactly as bulleted in the raw text product, just wrapped in XML. This
 * class only extracts what CAP already gives us structured: it does not
 * attempt the narrative-bullet-splitting problem.
 *
 * A single <info>/<area> block is assumed, matching every real CAP alert
 * confirmed against live NWWS traffic so far. A multi-area CAP alert (if
 * one is ever observed) would need this revisited -- not attempted here,
 * not guessed at.
 */
class ExtractCapMetadata
{
    /**
     * @return array{format: string, msg_type: ?string, event: ?string, severity: ?string, urgency: ?string, certainty: ?string, headline: ?string, description: ?string, instruction: ?string, event_motion_description: ?string, awips_identifier: ?string, wmo_identifier: ?string, product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: null, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?Carbon, vtec_end: ?Carbon, hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $text): array
    {
        $xmlStart = strpos($text, '<?xml');
        $xml = new SimpleXMLElement($xmlStart !== false ? substr($text, $xmlStart) : $text);
        $info = $xml->info;

        $parameters = $this->collectParameters($info);
        $vtecString = $parameters['VTEC'] ?? null;
        $vtec = $vtecString !== null ? (new ParseVtecString)($vtecString) : null;

        $productId = null;
        if ($vtec !== null) {
            $product = Product::where('code', "{$vtec['phenomenon']}.{$vtec['significance']}")->first();
            $productId = $product?->id;
        }

        $area = isset($info->area) ? $info->area : null;
        $description = (string) $info->description ?: null;
        $instruction = (string) $info->instruction ?: null;
        $narrative = (new ParseNarrativeSections)($description ?? '');

        return [
            'format' => 'cap',
            'msg_type' => (string) $xml->msgType ?: null,
            'event' => (string) $info->event ?: null,
            'severity' => (string) $info->severity ?: null,
            'urgency' => (string) $info->urgency ?: null,
            'certainty' => (string) $info->certainty ?: null,
            'headline' => (string) $info->headline ?: null,
            'description' => $description,
            'instruction' => $instruction,
            'event_motion_description' => $parameters['eventMotionDescription'] ?? null,
            'awips_identifier' => $parameters['AWIPSidentifier'] ?? null,
            'wmo_identifier' => $parameters['WMOidentifier'] ?? null,
            'product_id' => $productId,
            'ugc_codes' => $area !== null ? $this->extractUgcCodes($area) : null,
            'polygon' => $area !== null ? $this->extractPolygon($area) : null,
            'risk_levels' => null,
            'vtec_office' => $vtec['office'] ?? null,
            'vtec_phenomenon' => $vtec['phenomenon'] ?? null,
            'vtec_significance' => $vtec['significance'] ?? null,
            'vtec_event_number' => $vtec['event_number'] ?? null,
            'vtec_year' => $vtec['year'] ?? null,
            'vtec_action' => $vtec['action'] ?? null,
            'vtec_begin' => $vtec['begin'] ?? null,
            'vtec_end' => $vtec['end'] ?? null,
            'hazard' => $narrative['hazard'],
            'hazard_source' => $narrative['hazard_source'],
            'impact' => $narrative['impact'],
            'locations_impacted' => $narrative['locations_impacted'],
            // CAP's own instruction is already reliably structured -- never
            // re-parsed from description's prose for this one field.
            'call_to_action' => $instruction,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function collectParameters(SimpleXMLElement $info): array
    {
        $parameters = [];

        foreach ($info->parameter as $parameter) {
            $parameters[(string) $parameter->valueName] = (string) $parameter->value;
        }

        return $parameters;
    }

    /**
     * @return ?array<int, string>
     */
    private function extractUgcCodes(SimpleXMLElement $area): ?array
    {
        $codes = [];

        foreach ($area->geocode as $geocode) {
            if ((string) $geocode->valueName === 'UGC') {
                $codes[] = (string) $geocode->value;
            }
        }

        return $codes === [] ? null : $codes;
    }

    /**
     * CAP's <polygon> is a single space-separated "lat,lon lat,lon ..."
     * string -- much simpler to decode than the raw text LAT...LON format
     * (no multi-digit coordinate encoding to reverse).
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function extractPolygon(SimpleXMLElement $area): ?array
    {
        $raw = trim((string) $area->polygon);

        if ($raw === '') {
            return null;
        }

        return collect(explode(' ', $raw))
            ->map(function (string $pair): array {
                [$lat, $lon] = explode(',', $pair);

                return ['lat' => (float) $lat, 'lon' => (float) $lon];
            })
            ->all();
    }
}
