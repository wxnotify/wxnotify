<?php

namespace App\Support\ProductStorage;

use App\Enums\ProductFamily;

/**
 * The catalog-group label of a product file: its catalog product's family, else the WMO data-type letter pair at the
 * start of its `ttaaii`, else "Unclassified". Files with no ingested_products row are UNMATCHED.
 */
class CatalogGroupLabel
{
    public const UNMATCHED = 'Unmatched';

    public const SMALLER = 'Smaller groups';

    public const UNCLASSIFIED = 'Unclassified';

    /** @var array<string, string> WMO T1 data-type letters */
    private const T1_NAMES = [
        'A' => 'analyses',
        'C' => 'climatic',
        'F' => 'forecasts',
        'N' => 'notices',
        'O' => 'oceanographic',
        'S' => 'surface observations',
        'T' => 'satellite',
        'U' => 'upper-air',
        'W' => 'warnings',
    ];

    public static function forRow(?ProductFamily $family, ?string $ttaaii): string
    {
        if ($family !== null) {
            return $family->label();
        }

        $pair = strtoupper(substr((string) $ttaaii, 0, 2));

        if (preg_match('/^[A-Z]{2}$/', $pair) !== 1) {
            return self::UNCLASSIFIED;
        }

        $name = self::T1_NAMES[$pair[0]] ?? null;

        return $name === null ? "WMO {$pair}" : "Other: {$name} ({$pair})";
    }
}
