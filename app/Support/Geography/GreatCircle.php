<?php

namespace App\Support\Geography;

/**
 * Straight-line distance between two coordinates on the Earth's surface.
 */
final class GreatCircle
{
    private const EARTH_RADIUS_METERS = 6371008.8;

    /** Haversine distance in meters. */
    public static function meters(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_RADIUS_METERS * asin(min(1.0, sqrt($a)));
    }
}
