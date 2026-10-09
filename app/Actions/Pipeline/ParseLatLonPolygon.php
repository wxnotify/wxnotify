<?php

namespace App\Actions\Pipeline;

/**
 * Decodes the "LAT...LON" polygon of a product's raw text (or of one section of it).
 */
class ParseLatLonPolygon
{
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
    public function __invoke(string $text): ?array
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
    public static function decodeCombinedToken(string $token): array
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
    public static function decodeCoordinatePart(string $raw): float
    {
        return strlen($raw) === 5
            ? (float) (substr($raw, 0, 3).'.'.substr($raw, 3))
            : (float) (substr($raw, 0, 2).'.'.substr($raw, 2));
    }
}
