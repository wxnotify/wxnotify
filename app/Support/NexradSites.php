<?php

namespace App\Support;

/**
 * The static list of NEXRAD (WSR-88D) radar sites, keyed by lower-case id.
 * A site code that reaches a URL must pass exists() first.
 */
class NexradSites
{
    /**
     * @var array<string, array{name: string, lat: float, lng: float}>|null
     */
    private static ?array $sites = null;

    /**
     * @return array<string, array{name: string, lat: float, lng: float}>
     */
    public static function all(): array
    {
        return self::$sites ??= require database_path('data/nexrad_sites.php');
    }

    public static function exists(string $site): bool
    {
        return isset(self::all()[strtolower($site)]);
    }

    /**
     * The great-circle nearest site to a point, as a lower-case id.
     */
    public static function nearest(float $lat, float $lng): ?string
    {
        $nearest = null;
        $best = INF;

        foreach (self::all() as $id => $site) {
            $distance = self::haversine($lat, $lng, $site['lat'], $site['lng']);

            if ($distance < $best) {
                $best = $distance;
                $nearest = $id;
            }
        }

        return $nearest;
    }

    private static function haversine(float $latA, float $lngA, float $latB, float $lngB): float
    {
        $dLat = deg2rad($latB - $latA);
        $dLng = deg2rad($lngB - $lngA);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($latA)) * cos(deg2rad($latB)) * sin($dLng / 2) ** 2;

        return 2 * asin(min(1.0, sqrt($a)));
    }
}
