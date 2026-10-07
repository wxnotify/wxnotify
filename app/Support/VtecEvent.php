<?php

namespace App\Support;

/**
 * Names a VTEC event from its two-letter phenomenon and one-letter significance, for an active
 * warning, watch or advisory the product catalog has no row for (the catalog is a curated subset:
 * Storm Surge, Rip Current, Gale and others are not in it). Every active event carries these codes,
 * so the name never depends on the bulletin text or a CAP event field being present.
 *
 * The phenomena are the NWS VTEC phenomena codes (NWSI 10-1703); the name is the phenomenon followed
 * by the significance word, which is how the NWS names them ("Rip Current" + "Statement"). Both FA
 * (areal flood) and FL (river flood) read "Flood": the NWS issues them as Flood Watch/Warning/Advisory.
 */
final class VtecEvent
{
    private const PHENOMENA = [
        'AF' => 'Ashfall', 'AS' => 'Air Stagnation', 'BH' => 'Beach Hazards', 'BS' => 'Blowing Snow',
        'BW' => 'Brisk Wind', 'BZ' => 'Blizzard', 'CF' => 'Coastal Flood', 'CW' => 'Cold Weather',
        'DS' => 'Dust Storm', 'DU' => 'Blowing Dust', 'EC' => 'Extreme Cold', 'EH' => 'Excessive Heat',
        'EW' => 'Extreme Wind', 'FA' => 'Flood', 'FF' => 'Flash Flood', 'FG' => 'Dense Fog',
        'FL' => 'Flood', 'FR' => 'Frost', 'FW' => 'Red Flag', 'FZ' => 'Freeze',
        'GL' => 'Gale', 'HF' => 'Hurricane Force Wind', 'HI' => 'Inland Hurricane Wind', 'HS' => 'Heavy Snow',
        'HT' => 'Heat', 'HU' => 'Hurricane', 'HW' => 'High Wind', 'HY' => 'Hydrologic',
        'HZ' => 'Hard Freeze', 'IP' => 'Sleet', 'IS' => 'Ice Storm', 'LB' => 'Lake Effect Snow and Blowing Snow',
        'LE' => 'Lake Effect Snow', 'LO' => 'Low Water', 'LS' => 'Lakeshore Flood', 'LW' => 'Lake Wind',
        'MA' => 'Special Marine', 'MF' => 'Marine Dense Fog', 'MH' => 'Marine Ashfall', 'MS' => 'Marine Dense Smoke',
        'RB' => 'Small Craft for Rough Bar', 'RP' => 'Rip Current', 'SB' => 'Snow and Blowing Snow', 'SC' => 'Small Craft',
        'SE' => 'Hazardous Seas', 'SI' => 'Small Craft for Winds', 'SM' => 'Dense Smoke', 'SN' => 'Snow',
        'SQ' => 'Snow Squall', 'SR' => 'Storm', 'SS' => 'Storm Surge', 'SU' => 'High Surf',
        'SV' => 'Severe Thunderstorm', 'SW' => 'Small Craft for Hazardous Seas', 'TI' => 'Inland Tropical Storm Wind', 'TO' => 'Tornado',
        'TR' => 'Tropical Storm', 'TS' => 'Tsunami', 'TY' => 'Typhoon', 'UP' => 'Heavy Freezing Spray',
        'WC' => 'Wind Chill', 'WI' => 'Wind', 'WS' => 'Winter Storm', 'WW' => 'Winter Weather',
        'XH' => 'Extreme Heat', 'ZF' => 'Freezing Fog', 'ZR' => 'Freezing Rain', 'ZY' => 'Freezing Spray',
    ];

    private const SIGNIFICANCE = [
        'W' => 'Warning', 'A' => 'Watch', 'Y' => 'Advisory', 'S' => 'Statement',
        'F' => 'Forecast', 'O' => 'Outlook', 'N' => 'Synopsis',
    ];

    /** The product families the dashboard colours and sorts by; only these four significances map to one. */
    private const FAMILIES = ['W' => 'warning', 'A' => 'watch', 'Y' => 'advisory', 'S' => 'statement'];

    /**
     * "Rip Current Statement" for ('RP', 'S'); null when either code is not one the NWS defines.
     */
    public static function name(string $phenomenon, string $significance): ?string
    {
        $phenomenonName = self::PHENOMENA[strtoupper($phenomenon)] ?? null;
        $significanceName = self::SIGNIFICANCE[strtoupper($significance)] ?? null;

        return $phenomenonName === null || $significanceName === null ? null : "{$phenomenonName} {$significanceName}";
    }

    /**
     * The family a significance letter belongs to ('warning', 'watch', 'advisory', 'statement'), else null.
     */
    public static function family(string $significance): ?string
    {
        return self::FAMILIES[strtoupper($significance)] ?? null;
    }
}
