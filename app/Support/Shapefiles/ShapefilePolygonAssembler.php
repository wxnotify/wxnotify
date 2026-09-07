<?php

namespace App\Support\Shapefiles;

final class ShapefilePolygonAssembler
{
    /**
     * @param  array<int, array{points: array<int, array{x: float, y: float}>}>  $parts
     */
    public static function toMultiPolygonWkt(array $parts): string
    {
        $polygons = self::assemble($parts);

        $polygonWkts = array_map(
            fn (array $rings): string => '('.implode(',', array_map(
                fn (array $ring): string => '('.self::ringToWkt($ring).')',
                $rings,
            )).')',
            $polygons,
        );

        return 'MULTIPOLYGON('.implode(',', $polygonWkts).')';
    }

    /**
     * @param  array<int, array{points: array<int, array{x: float, y: float}>}>  $parts
     * @return array<int, array<int, array<int, array{0: float, 1: float}>>>
     */
    private static function assemble(array $parts): array
    {
        $polygons = [];
        $currentPolygon = null;

        foreach ($parts as $part) {
            $ring = array_map(fn (array $point): array => [$point['x'], $point['y']], $part['points']);

            if ($currentPolygon === null || self::isClockwise($ring)) {
                if ($currentPolygon !== null) {
                    $polygons[] = $currentPolygon;
                }
                $currentPolygon = [$ring];
            } else {
                $currentPolygon[] = $ring;
            }
        }

        if ($currentPolygon !== null) {
            $polygons[] = $currentPolygon;
        }

        return $polygons;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private static function isClockwise(array $ring): bool
    {
        $sum = 0.0;
        $count = count($ring);

        for ($i = 0; $i < $count; $i++) {
            [$x1, $y1] = $ring[$i];
            [$x2, $y2] = $ring[($i + 1) % $count];
            $sum += ($x2 - $x1) * ($y2 + $y1);
        }

        return $sum > 0;
    }

    /**
     * @param  array<int, array{0: float, 1: float}>  $ring
     */
    private static function ringToWkt(array $ring): string
    {
        return implode(',', array_map(
            fn (array $point): string => self::formatNumber($point[0]).' '.self::formatNumber($point[1]),
            $ring,
        ));
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.7f', $value), '0'), '.') ?: '0';
    }
}
