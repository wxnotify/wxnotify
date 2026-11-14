<?php

namespace App\Support;

/**
 * A short, human label for a browser's User-Agent ("Chrome on macOS"), for the signed-in browsers list.
 * Deliberately small: anything it does not recognise reads "Unknown browser".
 */
class UserAgentLabel
{
    public static function for(?string $userAgent): string
    {
        if (blank($userAgent)) {
            return 'Unknown browser';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Safari/') && str_contains($userAgent, 'Version/') => 'Safari',
            default => null,
        };

        $system = match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Macintosh') => 'macOS',
            str_contains($userAgent, 'CrOS') => 'ChromeOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };

        if ($browser === null) {
            return 'Unknown browser';
        }

        return $system === null ? $browser : "{$browser} on {$system}";
    }
}
