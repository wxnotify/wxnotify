<?php

namespace App\Support;

/**
 * The one definition of what a position from Traccar's /api/positions must look
 * like before it may place a device, shared by the position poller and the
 * device map so the two cannot drift apart.
 *
 * Traccar returns a "last known position" for every device even when the
 * device never had a GPS fix; such a position is flagged `valid: false` and/or
 * sits at exactly 0,0 ("Null Island"). It is well-formed, so it must not void
 * the whole batch, but it must never become a location's pin. A real fix at
 * exactly 0,0 (open ocean off West Africa) is not a realistic location for this
 * service, while a single zero coordinate (the equator or the prime meridian)
 * is perfectly real.
 */
final class TraccarPosition
{
    /**
     * @param  array<string, mixed>  $position  a well-formed entry (numeric latitude/longitude)
     */
    public static function hasFix(array $position): bool
    {
        if (($position['valid'] ?? true) === false) {
            return false;
        }

        return ! ((float) ($position['latitude'] ?? 0) === 0.0 && (float) ($position['longitude'] ?? 0) === 0.0);
    }

    /**
     * Numeric, finite (is_numeric('1e999') is true, but it casts to INF) and
     * within +/- $limit degrees.
     */
    public static function isCoordinate(mixed $value, int $limit): bool
    {
        return is_numeric($value)
            && is_finite((float) $value)
            && abs((float) $value) <= $limit;
    }
}
