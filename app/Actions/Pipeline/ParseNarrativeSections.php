<?php

namespace App\Actions\Pipeline;

/**
 * Extracts the HAZARD/SOURCE/IMPACT bullets, a "locations impacted" line,
 * and a call-to-action section from a VTEC-class product's narrative text
 * -- the raw text bulletin body for a text-sourced IngestedProduct, or
 * CAP's <description> for a CAP-sourced one (both use the identical bullet
 * format; CAP does not pre-split this content -- see ExtractCapMetadata).
 *
 * Never guesses: a section this class can't confidently locate comes back
 * null. locations_impacted and call_to_action in particular only recognize
 * one real, standard NWS phrasing each ("Locations impacted include..." and
 * "PRECAUTIONARY/PREPAREDNESS ACTIONS...") -- a real bulletin using
 * different phrasing for either (this happens; NWS bulletins are not
 * perfectly standardized across offices/products) will legitimately return
 * null for that field. This is a known, accepted coverage gap, not a bug
 * to chase down -- see the design doc.
 */
class ParseNarrativeSections
{
    /**
     * @return array{hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $narrativeText): array
    {
        return [
            'hazard' => $this->extractSection($narrativeText, 'HAZARD'),
            'hazard_source' => $this->extractSection($narrativeText, 'SOURCE'),
            'impact' => $this->extractSection($narrativeText, 'IMPACT'),
            'locations_impacted' => $this->extractSection($narrativeText, 'Locations impacted include'),
            'call_to_action' => $this->extractSection($narrativeText, 'PRECAUTIONARY/PREPAREDNESS ACTIONS'),
        ];
    }

    /**
     * Finds a "$label..." header at the start of a line and returns
     * everything between it and the next section boundary, normalized to a
     * single line (real bulletins hard-wrap section bodies at ~70
     * characters). The boundary is the next recognized "LABEL..." header,
     * the "&&" end-of-narrative delimiter, or the end of the text --
     * whichever comes first.
     *
     * Deliberately does NOT stop at the first blank line: some sections
     * (PRECAUTIONARY/PREPAREDNESS ACTIONS in particular) conventionally put
     * a blank line between the label and its body, which a naive
     * first-blank-line stop would misread as "no content".
     */
    private function extractSection(string $text, string $label): ?string
    {
        $startPattern = '/^'.preg_quote($label, '/').'\.\.\./m';

        if (! preg_match($startPattern, $text, $startMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $contentStart = $startMatch[0][1] + strlen($startMatch[0][0]);
        $remaining = substr($text, $contentStart);

        if (preg_match('/\n(?:[A-Z][A-Z \/]{2,}\.\.\.|&&)/', $remaining, $endMatch, PREG_OFFSET_CAPTURE)) {
            $remaining = substr($remaining, 0, $endMatch[0][1]);
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $remaining));

        return $normalized === '' ? null : $normalized;
    }
}
