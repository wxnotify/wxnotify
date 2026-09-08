<?php

namespace App\Actions\Pipeline;

use App\Models\Product;

/**
 * Extracts VTEC-resolved product_id, UGC zone/county codes, and a parsed
 * severe-weather polygon from a raw NWWS product's text, for storage on
 * IngestedProduct at ingest time (see IngestNwwsProductController).
 *
 * Non-VTEC AWIPS-ID prefix matching only covers AFD today. SPC/WPC prefixes
 * (SPC.OUTLOOK/SPC.MD/SPC.FIRE/WPC.MPD) were unconfirmed against real live
 * NWWS traffic at planning time (a 613-row live sample had zero SPC/WPC
 * products) and are deliberately left out rather than guessed — see the
 * design doc's "Known gaps" section and this plan's "Scope decision".
 * AlertCategory::SpcWpc rules can still be created; they just won't resolve
 * a product_id or match until this crosswalk is extended with confirmed
 * prefixes.
 */
class ExtractProductMetadata
{
    private const AWIPS_PREFIX_CROSSWALK = [
        'afd' => 'AFD',
    ];

    /**
     * @return array{product_id: ?int, ugc_codes: ?array<int, string>, polygon: ?array<int, array{lat: float, lon: float}>}
     */
    public function __invoke(string $text, ?string $awipsid): array
    {
        return [
            'product_id' => $this->resolveProductId($text, $awipsid),
            'ugc_codes' => $this->parseUgcCodes($text),
            'polygon' => null,
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
     */
    private function resolveProductId(string $text, ?string $awipsid): ?int
    {
        if (preg_match('/\/[A-Z]\.[A-Z]{3}\.[A-Z]{4}\.([A-Z]{2})\.([A-Z])\.\d{4}\.\d{6}T\d{4}Z-\d{6}T\d{4}Z\//', $text, $matches)) {
            $product = Product::where('code', "{$matches[1]}.{$matches[2]}")->first();

            if ($product !== null) {
                return $product->id;
            }
        }

        $prefix = $awipsid !== null ? substr(strtolower($awipsid), 0, 3) : null;
        $code = $prefix !== null ? (self::AWIPS_PREFIX_CROSSWALK[$prefix] ?? null) : null;

        return $code !== null ? Product::where('code', $code)->first()?->id : null;
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
}
