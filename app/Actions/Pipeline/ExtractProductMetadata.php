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
     * SPC Fire Weather Outlook risk levels ("Elevated"/"Critical"/etc.) are
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
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => $isCategoricalAreal ? null : $this->parsePolygon($text),
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
                array_push($areas[$currentLevel], ...array_map(self::decodeCombinedToken(...), $matches[0]));
            }
        }

        return array_filter($areas, fn (array $points): bool => count($points) >= 3);
    }

    /**
     * Standard NWS UGC line: dash-separated zone/county codes, immediately
     * before the VTEC line, terminated by a 6-digit DDHHMM purge timestamp
     * (e.g. "NYZ069-070>075-081200-"). A bare 3-digit token continues the
     * most recently seen state+type prefix; a "070>075" token expands to
     * every code in that numeric range under the current prefix.
     *
     * @return ?array<int, string>
     */
    private function parseUgcCodes(string $text): ?array
    {
        if (! preg_match('/^((?:[A-Z]{2}[CZ]\d{3}|\d{3}(?:>\d{3})?)(?:-(?:[A-Z]{2}[CZ]\d{3}|\d{3}(?:>\d{3})?))*)-\d{6}-\s*$/m', $text, $matches)) {
            return null;
        }

        $codes = [];
        $prefix = null;

        foreach (explode('-', $matches[1]) as $token) {
            if (preg_match('/^([A-Z]{2}[CZ])(\d{3})$/', $token, $m)) {
                $prefix = $m[1];
                $codes[] = "{$prefix}{$m[2]}";
            } elseif ($prefix !== null && preg_match('/^(\d{3})>(\d{3})$/', $token, $m)) {
                foreach (range((int) $m[1], (int) $m[2]) as $n) {
                    $codes[] = $prefix.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
                }
            } elseif ($prefix !== null && preg_match('/^\d{3}$/', $token)) {
                $codes[] = $prefix.$token;
            }
        }

        return $codes === [] ? null : $codes;
    }

    /**
     * Finds a "LAT...LON" line, collects wrapped continuation lines until a
     * blank line or a "TIME..." line, and decodes each coordinate token.
     * Doesn't pre-filter by product type (the legacy reference implementation
     * gates this on a fixed list of AWIPS product types) — scanning every
     * product's text for the line and returning null when absent is simpler
     * and has the same practical effect, since most VTEC products never
     * carry one.
     *
     * @return ?array<int, array{lat: float, lon: float}>
     */
    private function parsePolygon(string $text): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $collecting = false;
        $tokens = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (! $collecting) {
                if (! str_starts_with($trimmed, 'LAT...LON')) {
                    continue;
                }

                $collecting = true;
                $trimmed = trim(substr($trimmed, strlen('LAT...LON')));
            } elseif ($trimmed === '' || str_starts_with($trimmed, 'TIME...')) {
                break;
            } elseif (! preg_match('/^\d/', $trimmed)) {
                // Mirrors the legacy reference's ^\s*\d+ gate on continuation
                // lines: a non-digit-leading line (e.g. stray remarks text
                // before TIME...) is skipped, not appended -- otherwise a
                // preg_match_all() scanning the whole line could splice
                // unrelated digits into the polygon instead of failing safe.
                continue;
            }

            if (preg_match_all('/\d{4,9}/', $trimmed, $matches)) {
                array_push($tokens, ...$matches[0]);
            }
        }

        if ($tokens === []) {
            return null;
        }

        // SPC MD/WPC MPD products encode each lat/lon pair as one
        // concatenated 8-9 digit token (e.g. "33311429") rather than
        // warning-family's two separate space-delimited 4-5 digit tokens
        // (e.g. "3650 9082") -- confirmed against this repo's own real SPC
        // MCD fixture, database/product_examples/SWO.txt. A product uses
        // one convention consistently, so the presence of any combined
        // token switches parsing for the whole line.
        $isCombinedFormat = collect($tokens)->contains(fn (string $token): bool => strlen($token) >= 8);

        if ($isCombinedFormat) {
            $points = collect($tokens)->map(fn (string $token): array => self::decodeCombinedToken($token))->all();

            return count($points) >= 3 ? $points : null;
        }

        if (count($tokens) < 6 || count($tokens) % 2 !== 0) {
            return null;
        }

        return collect(array_chunk($tokens, 2))
            ->map(fn (array $pair): array => [
                'lat' => self::decodeCoordinatePart($pair[0]),
                'lon' => -self::decodeCoordinatePart($pair[1]),
            ])
            ->all();
    }

    /**
     * Decodes one combined lat/lon token: the first 4 digits are latitude
     * (decimal after 2, as usual); the remaining 4 digits are longitude,
     * ALWAYS read directly (decimal after 2) -- never with a leading "1"
     * blindly prepended. That prior rule was reverse-engineered from a
     * single fixture (database/product_examples/SWO.txt's real Arizona MD,
     * token "33311429") where the true longitude genuinely is >= 100
     * degrees (114.29), and prepending "1" happened to fix that one case.
     * It silently breaks for a longitude < 100 -- confirmed against a real
     * PTSDY1 (SPC Day 1 Outlook Areal Outline) bulletin's own categorical
     * risk-area coordinates: token "32959919" decodes correctly, directly,
     * to (32.95, -99.19) -- almost exactly between Abilene and Wichita
     * Falls, TX, matching that same bulletin's own "...TO THE RIGHT OF A
     * LINE FROM 45 NE ABI 65 WSW SPS..." text -- while prepending "1"
     * produces an impossible -199.19.
     *
     * The actual rule: decode the 4-digit remainder directly; if that
     * yields a magnitude under 60 (no real CONUS longitude is ever that
     * small -- Maine, the easternmost point, is ~67), the source must have
     * dropped a leading "1" digit from a true value >= 100, so restore it.
     * A genuine < 100 value (the common case east of the Rockies -- the
     * Great Plains/Midwest/South are almost entirely in the 90s) and a
     * genuine >= 100 value transmitted this way (114.29 minus its dropped
     * leading digit lands at 14.29) can never collide in that gap; both
     * real fixtures above decode correctly, ring-wide, against this rule.
     *
     * @return array{lat: float, lon: float}
     */
    private static function decodeCombinedToken(string $token): array
    {
        $lat = substr($token, 0, 4);
        $lonDirect = self::decodeCoordinatePart(substr($token, 4));
        $lon = $lonDirect < 60 ? self::decodeCoordinatePart('1'.substr($token, 4)) : $lonDirect;

        return [
            'lat' => self::decodeCoordinatePart($lat),
            'lon' => -$lon,
        ];
    }

    /**
     * Mirrors App\Libraries\AwipsProduct::getAffectedAreaCoords()'s real
     * decode math: a 4-digit value inserts the decimal after 2 digits
     * (e.g. "3982" -> 39.82), a 5-digit value inserts it after 3 (e.g.
     * "10066" -> 100.66 -- needed for longitudes >= 100 degrees).
     */
    private static function decodeCoordinatePart(string $raw): float
    {
        return strlen($raw) === 5
            ? (float) (substr($raw, 0, 3).'.'.substr($raw, 3))
            : (float) (substr($raw, 0, 2).'.'.substr($raw, 2));
    }
}
