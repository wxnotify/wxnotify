<?php

namespace App\Support\Geography;

/**
 * Maps a us_states shapefile "state" abbreviation to its most-populous IANA
 * timezone. Several states straddle two zones (e.g. TX, FL, MI, ID) — this
 * intentionally picks one as a best-guess fallback, matching this app's own
 * "auto-filled, correct it if wrong" pattern already used for the timezone
 * field elsewhere (LocationForm's helper text) rather than requiring exact
 * per-county timezone boundaries this app doesn't have data for.
 */
class StateTimezones
{
    /**
     * @var array<string, string>
     */
    private const MAP = [
        'AK' => 'America/Anchorage',
        'AL' => 'America/Chicago',
        'AR' => 'America/Chicago',
        'AS' => 'Pacific/Pago_Pago',
        'AZ' => 'America/Phoenix',
        'CA' => 'America/Los_Angeles',
        'CO' => 'America/Denver',
        'CT' => 'America/New_York',
        'DC' => 'America/New_York',
        'DE' => 'America/New_York',
        'FL' => 'America/New_York',
        'FM' => 'Pacific/Chuuk',
        'GA' => 'America/New_York',
        'GU' => 'Pacific/Guam',
        'HI' => 'Pacific/Honolulu',
        'IA' => 'America/Chicago',
        'ID' => 'America/Boise',
        'IL' => 'America/Chicago',
        'IN' => 'America/Indiana/Indianapolis',
        'KS' => 'America/Chicago',
        'KY' => 'America/New_York',
        'LA' => 'America/Chicago',
        'MA' => 'America/New_York',
        'MD' => 'America/New_York',
        'ME' => 'America/New_York',
        'MH' => 'Pacific/Majuro',
        'MI' => 'America/Detroit',
        'MN' => 'America/Chicago',
        'MO' => 'America/Chicago',
        'MP' => 'Pacific/Saipan',
        'MS' => 'America/Chicago',
        'MT' => 'America/Denver',
        'NC' => 'America/New_York',
        'ND' => 'America/Chicago',
        'NE' => 'America/Chicago',
        'NH' => 'America/New_York',
        'NJ' => 'America/New_York',
        'NM' => 'America/Denver',
        'NV' => 'America/Los_Angeles',
        'NY' => 'America/New_York',
        'OH' => 'America/New_York',
        'OK' => 'America/Chicago',
        'OR' => 'America/Los_Angeles',
        'PA' => 'America/New_York',
        'PR' => 'America/Puerto_Rico',
        'PW' => 'Pacific/Palau',
        'RI' => 'America/New_York',
        'SC' => 'America/New_York',
        'SD' => 'America/Chicago',
        'TN' => 'America/Chicago',
        'TX' => 'America/Chicago',
        'UT' => 'America/Denver',
        'VA' => 'America/New_York',
        'VI' => 'America/St_Thomas',
        'VT' => 'America/New_York',
        'WA' => 'America/Los_Angeles',
        'WI' => 'America/Chicago',
        'WV' => 'America/New_York',
        'WY' => 'America/Denver',
    ];

    public static function forState(string $state): ?string
    {
        return self::MAP[strtoupper($state)] ?? null;
    }
}
