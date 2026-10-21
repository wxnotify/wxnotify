<?php

namespace App\Actions\Pipeline;

use App\Models\Product;
use Illuminate\Support\Carbon;

/**
 * Extracts VTEC-resolved product_id, UGC zone/county codes, a parsed
 * severe-weather polygon, and (for the SPC Day 1 Convective Outlook only)
 * categorical risk levels from a raw NWWS product's text, for storage on
 * IngestedProduct at ingest time (see IngestNwwsProductController). Also
 * extracts the shared vtec_* identity/timing fields (via ParseVtecString)
 * and the hazard/hazard_source/impact/locations_impacted/call_to_action
 * narrative fields (via ParseNarrativeSections), both run directly against
 * the raw text -- unlike ExtractCapMetadata, which runs ParseNarrativeSections
 * against CAP's <description> for the first four of those but sources
 * call_to_action from CAP's own <instruction> field instead (see that
 * class's docblock); this class has no such override, since a text product
 * has no equivalent to <instruction>.
 *
 * Non-VTEC AWIPS-ID matching covers AFD (per-office prefix) plus a set of
 * SPC/WPC products confirmed against real live NWWS traffic and NOAA
 * service-change documentation: SWODY1/2/3 + SWOD48 (convective outlooks),
 * SWOMCD (mesoscale discussion), FWDDY1/FWDDY2/FWDD38 (the Day 1, Day 2
 * and Day 3-8 fire weather outlooks; note the doubled D, the ids below were
 * once written FWDY1/2 and silently matched nothing) and FFGMPD (WPC
 * mesoscale precipitation discussion). Two catalog codes — SPC Public
 * Severe Weather Outlook (PWO) and SPC Tornado/Severe Thunderstorm
 * Reports (STA) — could not be confirmed against a real awipsid (they
 * look like they may be issued with a per-office or per-state suffix, not
 * a single fixed id) and are deliberately left out rather than guessed —
 * matching this file's existing treatment of unconfirmed prefixes. Rules
 * for these two can still be created; they just won't resolve a
 * product_id or match until a confirmed id is added here.
 */
class ExtractProductMetadata
{
    private const AWIPS_PREFIX_CROSSWALK = [
        'afd' => 'AFD',
    ];

    /**
     * Full, fixed AWIPS ids (not per-office prefixes) for SPC/WPC products
     * that are centrally issued under one identifier.
     */
    private const AWIPS_EXACT_CROSSWALK = [
        'swody1' => 'SPC.OUTLOOK',
        'ptsdy1' => 'SPC.OUTLOOK',
        'swody2' => 'DY2',
        'swody3' => 'DY3',
        'swod48' => 'D48',
        'swomcd' => 'SPC.MD',
        'fwddy1' => 'SPC.FIRE',
        'fwddy2' => 'FD2',
        'fwdd38' => 'F38',
        'ffgmpd' => 'WPC.MPD',
    ];

    /**
     * Only the SPC Day 1 Convective Outlook's raw text has a confirmed,
     * stable categorical-risk phrase (see extractConvectiveOutlookRiskLevels()).
     * SPC Day 1 Fire Weather Outlook risk levels ("Elevated"/"Critical"/etc.) are
     * stated as free-form narrative prose with no fixed sentence found in
     * real sampled products — extraction for it is a known gap, not
     * attempted here rather than guessed at.
     */
    private const CONVECTIVE_OUTLOOK_CODE = 'SPC.OUTLOOK';

    /**
     * PTSDY1 ("Day 1 Convective Outlook Areal Outline") resolves to the same
     * SPC.OUTLOOK catalog product as its paired narrative SWODY1 bulletin
     * (so a subscriber only ever picks "SPC Convective Outlook" once), but
     * is a structurally distinct product carrying the real per-risk-level
     * polygon data SWODY1's prose never does — see extractCategoricalRiskAreas().
     * App\Actions\Pipeline\BackfillOutlookRiskAreas is what actually merges
     * the two bulletins into one matchable event rather than two.
     */
    private const CATEGORICAL_AREAL_AWIPS_ID = 'ptsdy1';

    /**
     * @return array{product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>, risk_levels: ?array<int, string>, risk_level_polygons: ?array<string, array<int, array{lat: float, lon: float}>>, vtec_office: ?string, vtec_phenomenon: ?string, vtec_significance: ?string, vtec_event_number: ?string, vtec_year: ?int, vtec_action: ?string, vtec_begin: ?Carbon, vtec_end: ?Carbon, hazard: ?string, hazard_source: ?string, impact: ?string, locations_impacted: ?string, call_to_action: ?string}
     */
    public function __invoke(string $text, ?string $awipsid): array
    {
        [$productId, $code] = $this->resolveProduct($text, $awipsid);
        $vtec = (new ParseVtecString)($text);
        $narrative = (new ParseNarrativeSections)($text);
        $isCategoricalAreal = $awipsid !== null && strtolower($awipsid) === self::CATEGORICAL_AREAL_AWIPS_ID;
        $riskAreas = $isCategoricalAreal ? $this->extractCategoricalRiskAreas($text) : null;

        return [
            'product_id' => $productId,
            'ugc_codes' => (new ParseUgcLine)($text),
            'polygon' => $isCategoricalAreal ? null : (new ParseLatLonPolygon)($text),
            'risk_levels' => match (true) {
                $isCategoricalAreal => array_keys($riskAreas),
                $code === self::CONVECTIVE_OUTLOOK_CODE => $this->extractConvectiveOutlookRiskLevels($text),
                default => null,
            },
            'risk_level_polygons' => $riskAreas,
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
            'call_to_action' => $narrative['call_to_action'],
        ];
    }

    /**
     * A product can carry multiple VTEC lines (e.g. canceling one warning
     * while issuing another). preg_match() (not preg_match_all()) returns
     * the leftmost match deliberately: whichever VTEC line appears first in
     * the raw text wins, regardless of its action code (NEW/CON/CAN/EXP/...)
     * — a canceled hazard's line is NOT filtered out or deprioritized. Do
     * not "fix" this into an action-code-aware filter; that would silently
     * change matching behavior on multi-VTEC bulletins (see the "two VTEC
     * lines" test in ExtractProductMetadataTest).
     *
     * @return array{0: ?int, 1: ?string} the resolved product's id and catalog code
     */
    private function resolveProduct(string $text, ?string $awipsid): array
    {
        if (preg_match('/\/[A-Z]\.[A-Z]{3}\.[A-Z]{4}\.([A-Z]{2})\.([A-Z])\.\d{4}\.\d{6}T\d{4}Z-\d{6}T\d{4}Z\//', $text, $matches)) {
            $product = Product::where('code', "{$matches[1]}.{$matches[2]}")->first();

            if ($product !== null) {
                return [$product->id, $product->code];
            }
        }

        $lowerAwipsid = $awipsid !== null ? strtolower($awipsid) : null;
        $code = $lowerAwipsid !== null ? (self::AWIPS_EXACT_CROSSWALK[$lowerAwipsid] ?? null) : null;

        if ($code === null) {
            $prefix = $lowerAwipsid !== null ? substr($lowerAwipsid, 0, 3) : null;
            $code = $prefix !== null ? (self::AWIPS_PREFIX_CROSSWALK[$prefix] ?? null) : null;
        }

        if ($code === null) {
            return [null, null];
        }

        $product = Product::where('code', $code)->first();

        return [$product?->id, $product !== null ? $code : null];
    }

    /**
     * The SPC Day 1 Convective Outlook states its categorical risk(s) in a
     * fixed, decades-old template sentence — confirmed against real
     * archived bulletins, e.g. "...THERE IS A MODERATE RISK OF SEVERE
     * THUNDERSTORMS FOR SOUTHWEST AND CENTRAL KANSAS..." and "...THERE IS A
     * SLIGHT RISK OF SEVERE THUNDERSTORMS FROM EASTERN MISSISSIPPI...". A
     * single bulletin can state more than one risk level for different
     * areas (as in that same real example), so every occurrence is
     * collected, not just the first. Returns an empty array (not null) when
     * the text has no such sentence at all — a real, legitimate "no
     * categorical risk today" result (e.g. "...NO SEVERE THUNDERSTORM AREAS
     * FORECAST..."), distinct from null, which this class only ever returns
     * for a product type risk-level extraction doesn't apply to.
     *
     * @return array<int, string>
     */
    private function extractConvectiveOutlookRiskLevels(string $text): array
    {
        if (! preg_match_all('/THERE IS A (MARGINAL|SLIGHT|ENHANCED|MODERATE|HIGH) RISK OF SEVERE (?:THUNDERSTORMS|TSTMS)/i', $text, $matches)) {
            return [];
        }

        return collect($matches[1])
            ->map(fn (string $level): string => ucfirst(strtolower($level)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * PTSDY1's "CATEGORICAL OUTLOOK POINTS DAY 1" section lists one
     * combined-token coordinate ring per named risk level (MDT/ENH/SLGT/
     * MRGL/HIGH), each starting on its own labeled line and continuing
     * (indented, unlabeled) until the next labeled line, a blank line, or
     * the section's closing "&&" -- confirmed against a real captured
     * bulletin (2024-04-27 1257Z; see decodeCombinedToken()'s docblock for
     * the coordinate format itself, verified against this same bulletin).
     * TSTM (general thunderstorm, below Marginal) and SIGN (a "significant
     * severe" probability overlay, not a risk level) both appear in this
     * section too but neither is one of the named categories
     * Product::risk_level_options tracks, so neither is collected.
     *
     * @return array<string, array<int, array{lat: float, lon: float}>>
     */
    private function extractCategoricalRiskAreas(string $text): array
    {
        $labels = ['MDT' => 'Moderate', 'ENH' => 'Enhanced', 'SLGT' => 'Slight', 'MRGL' => 'Marginal', 'HIGH' => 'High'];
        $areas = [];
        $currentLevel = null;

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $trimmed = trim($line);

            if (preg_match('/^(MDT|ENH|SLGT|MRGL|HIGH)\b(.*)$/', $trimmed, $matches)) {
                $currentLevel = $labels[$matches[1]];
                $areas[$currentLevel] ??= [];
                $trimmed = trim($matches[2]);
            } elseif (preg_match('/^(TSTM|SIGN)\b/', $trimmed) || $trimmed === '' || $trimmed === '&&') {
                $currentLevel = null;

                continue;
            } elseif ($currentLevel === null || ! preg_match('/^\d/', $trimmed)) {
                continue;
            }

            if ($currentLevel !== null && preg_match_all('/\d{8}/', $trimmed, $matches)) {
                array_push($areas[$currentLevel], ...array_map(ParseLatLonPolygon::decodeCombinedToken(...), $matches[0]));
            }
        }

        return array_filter($areas, fn (array $points): bool => count($points) >= 3);
    }
}
