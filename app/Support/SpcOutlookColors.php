<?php

namespace App\Support;

/**
 * The colours the Live map fills SPC convective outlook categories with (SPC's own scheme), keyed
 * by the categorical layer's LABEL, from the lowest category to the highest.
 */
class SpcOutlookColors
{
    /**
     * @var array<string, array{name: string, color: string}>
     */
    private const CATEGORIES = [
        'TSTM' => ['name' => 'General thunder', 'color' => '#c1e9c1'],
        'MRGL' => ['name' => 'Marginal', 'color' => '#66c57d'],
        'SLGT' => ['name' => 'Slight', 'color' => '#f6f67b'],
        'ENH' => ['name' => 'Enhanced', 'color' => '#e6b366'],
        'MDT' => ['name' => 'Moderate', 'color' => '#e67f7f'],
        'HIGH' => ['name' => 'High', 'color' => '#ff7cff'],
    ];

    private const OTHER = '#6b7280';

    public static function for(string $label): string
    {
        return self::CATEGORIES[$label]['color'] ?? self::OTHER;
    }

    /**
     * The categories for the map's legend, lowest first.
     *
     * @return list<array{label: string, name: string, color: string}>
     */
    public static function legend(): array
    {
        $legend = [];

        foreach (self::CATEGORIES as $label => $category) {
            $legend[] = ['label' => $label, ...$category];
        }

        return $legend;
    }
}
