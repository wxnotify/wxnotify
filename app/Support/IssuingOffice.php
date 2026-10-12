<?php

namespace App\Support;

/**
 * Maps an NWS office code (the `cwa` that `GET /nws-offices` lists) to the WMO issuing centres (`cccc`) that publish for it.
 */
final class IssuingOffice
{
    /** Offices whose centre code is not "K" or "P" followed by the office code. */
    private const EXCEPTIONS = ['SJU' => ['tjsj'], 'PPG' => ['nstu']];

    /**
     * @return list<string> lowercase, as `ingested_products.cccc` is stored
     */
    public static function centres(string $wfo): array
    {
        $code = strtolower($wfo);

        return [...(self::EXCEPTIONS[strtoupper($wfo)] ?? []), 'k'.$code, 'p'.$code];
    }
}
