<?php

namespace App\Actions\Pipeline;

/**
 * Extracts the HAZARD/SOURCE/IMPACT bullets, a "locations impacted" line,
 * and a call-to-action section from a VTEC-class product's narrative text
 * -- the raw text bulletin body for a text-sourced IngestedProduct, or
 * CAP's <description> for a CAP-sourced one (both use the identical bullet
 * format; CAP does not pre-split this content -- see ExtractCapMetadata).
 *
 * Real NWS bulletins indent HAZARD/SOURCE/IMPACT/PRECAUTIONARY... by 2
 * spaces under their parent "* At <time>..." bullet, and "Locations
 * impacted include..." itself appears as a "* "-prefixed bullet -- both
 * confirmed against this repo's own database/product_examples/FFW.txt and
 * SVW.txt. A section's body either starts on the same line as its label
 * (HAZARD/SOURCE/IMPACT) or, when the label line has nothing after it
 * (PRECAUTIONARY/PREPAREDNESS ACTIONS..., "Locations impacted include..."),
 * on the next non-blank line -- this distinction matters because the
 * former must be captured even though it runs on for multiple wrapped
 * lines with no internal blank line, while a naive "stop at the first
 * blank line after the label" rule would incorrectly return nothing for
 * the latter, since a blank line conventionally follows those labels
 * before their real content begins.
 *
 * Never guesses: a section this class can't confidently locate comes back
 * null. locations_impacted and call_to_action in particular only recognize
 * one real, standard NWS phrasing each ("Locations impacted include..." and
 * "PRECAUTIONARY/PREPAREDNESS ACTIONS...") -- a real bulletin using
 * different phrasing for either (this happens; NWS bulletins are not
 * perfectly standardized across offices/products -- e.g. this repo's own
 * FFW.txt uses "Some locations that will experience flash flooding
 * include..." instead) will legitimately return null for that field. This
 * is a known, accepted coverage gap, not a bug to chase down -- see the
 * design doc.
 */
class ParseNarrativeSections
{
    /**
     * Matches the next section boundary: another recognized "LABEL..."
     * header (optionally "* "-prefixed and/or indented), or the "&&" / "$$"
     * end-of-segment delimiters -- always preceded by a newline.
     */
    private const BOUNDARY_PATTERN = '/\n[ \t]*(?:\*?[ \t]*[A-Z][A-Z \/]{2,}\.\.\.|&&|\$\$)/';

    /**
     * @return array{hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $narrativeText): array
    {
        $text = preg_replace('/\r\n|\r/', "\n", $narrativeText);

        return [
            'hazard' => $this->extractSection($text, 'HAZARD'),
            'hazard_source' => $this->extractSection($text, 'SOURCE'),
            'impact' => $this->extractSection($text, 'IMPACT'),
            'locations_impacted' => $this->extractSection($text, 'Locations impacted include'),
            'call_to_action' => $this->extractSection($text, 'PRECAUTIONARY/PREPAREDNESS ACTIONS', stopAtBlankLine: false),
        ];
    }

    /**
     * Locates "$label..." (optionally indented and/or "* "-prefixed) and
     * returns its body: the rest of that same line if there's inline
     * content, otherwise the next non-blank line onward. The body runs
     * until the next blank line, the next section boundary, or end of
     * text -- whichever comes first.
     */
    private function extractSection(string $text, string $label, bool $stopAtBlankLine = true): ?string
    {
        $startPattern = '/^[ \t]*(?:\*[ \t]*)?'.preg_quote($label, '/').'\.\.\./m';

        if (! preg_match($startPattern, $text, $startMatch, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $labelEnd = $startMatch[0][1] + strlen($startMatch[0][0]);
        $inlineContent = $this->restOfLine($text, $labelEnd);

        $bodyStart = trim($inlineContent) !== ''
            ? $labelEnd
            : $this->skipToNextNonBlankLine($text, $labelEnd);

        if ($bodyStart === null) {
            return null;
        }

        $remaining = substr($text, $bodyStart);
        $end = $this->findSectionEnd($remaining, $stopAtBlankLine);
        $body = $end !== null ? substr($remaining, 0, $end) : $remaining;

        $normalized = trim(preg_replace('/\s+/', ' ', $body));

        return $normalized === '' ? null : $normalized;
    }

    private function restOfLine(string $text, int $offset): string
    {
        $newlinePos = strpos($text, "\n", $offset);

        return $newlinePos === false ? substr($text, $offset) : substr($text, $offset, $newlinePos - $offset);
    }

    /**
     * Skips past the label's own line-ending, then past any further fully
     * blank lines, to find where a "block-body" section's real content
     * starts (PRECAUTIONARY/PREPAREDNESS ACTIONS... conventionally has a
     * blank line between the label and its body; "Locations impacted
     * include..." conventionally has none -- this handles both).
     */
    private function skipToNextNonBlankLine(string $text, int $offset): ?int
    {
        $newlinePos = strpos($text, "\n", $offset);

        if ($newlinePos === false) {
            return null;
        }

        $tail = substr($text, $newlinePos + 1);
        preg_match('/\A(?:[ \t]*\n)*/', $tail, $blankMatch);
        $bodyStart = $newlinePos + 1 + strlen($blankMatch[0]);

        return $bodyStart < strlen($text) ? $bodyStart : null;
    }

    /**
     * Finds where a section's body ends: either a blank line (paragraph
     * break) or the next section boundary (another LABEL..., "&&", or
     * "$$"), whichever comes first -- correct for HAZARD/SOURCE/IMPACT and
     * locations_impacted, which are always single-paragraph in real
     * bulletins. call_to_action passes $stopAtBlankLine: false, since real
     * PRECAUTIONARY/PREPAREDNESS ACTIONS sections are routinely
     * multi-paragraph and must run through the boundary marker or end of
     * text instead, per the design spec -- stopping it at the first blank
     * line would silently drop later paragraphs, including real safety
     * instructions.
     *
     * A match at offset 0 must still count as valid, which is why this
     * checks `=== null` rather than relying on truthiness.
     */
    private function findSectionEnd(string $remaining, bool $stopAtBlankLine): ?int
    {
        $blankLinePos = $stopAtBlankLine && preg_match('/\n[ \t]*\n/', $remaining, $blankMatch, PREG_OFFSET_CAPTURE)
            ? $blankMatch[0][1]
            : null;

        $boundaryPos = preg_match(self::BOUNDARY_PATTERN, $remaining, $boundaryMatch, PREG_OFFSET_CAPTURE)
            ? $boundaryMatch[0][1]
            : null;

        return match (true) {
            $blankLinePos === null && $boundaryPos === null => null,
            $blankLinePos === null => $boundaryPos,
            $boundaryPos === null => $blankLinePos,
            default => min($blankLinePos, $boundaryPos),
        };
    }
}
