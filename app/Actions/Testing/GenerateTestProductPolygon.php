<?php

namespace App\Actions\Testing;

/**
 * Builds the polygon points for a synthetic warning-family product's
 * LAT...LON line -- either a simple box generated around an admin-picked
 * center point (the guided path), or parsed directly from a pasted raw
 * coordinate list (the advanced path). Deliberately a flat-earth
 * approximation (69 miles per degree of latitude, adjusted for longitude by
 * the cosine of latitude) -- accurate enough for a test-data box at the
 * radii this tool supports, not meant for real geodesy.
 */
class GenerateTestProductPolygon
{
    private const MILES_PER_DEGREE_LATITUDE = 69.0;

    /**
     * @return array<int, array{lat: float, lon: float}>
     */
    public function fromPointAndRadius(float $lat, float $lon, float $radiusMiles): array
    {
        $latOffset = $radiusMiles / self::MILES_PER_DEGREE_LATITUDE;
        $lonOffset = $radiusMiles / (self::MILES_PER_DEGREE_LATITUDE * max(cos(deg2rad($lat)), 0.01));

        return [
            ['lat' => $lat + $latOffset, 'lon' => $lon],
            ['lat' => $lat, 'lon' => $lon + $lonOffset],
            ['lat' => $lat - $latOffset, 'lon' => $lon],
            ['lat' => $lat, 'lon' => $lon - $lonOffset],
        ];
    }

    /**
     * @return ?array<int, array{lat: float, lon: float}>
     */
    public function fromRawText(string $raw): ?array
    {
        $points = collect(preg_split('/\r\n|\r|\n/', $raw))
            ->map(fn (string $line): ?array => $this->parseLine($line))
            ->filter()
            ->values()
            ->all();

        return count($points) >= 3 ? $points : null;
    }

    /**
     * @return ?array{lat: float, lon: float}
     */
    private function parseLine(string $line): ?array
    {
        if (! preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/', $line, $m)) {
            return null;
        }

        return ['lat' => (float) $m[1], 'lon' => (float) $m[2]];
    }
}
