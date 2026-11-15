<?php

namespace App\Support\Shapefiles;

/**
 * Turns the parts (rings) of shapefile polygon records into one MULTIPOLYGON WKT. A ring that runs
 * clockwise starts a new polygon, and a counter-clockwise ring after it is a hole in it.
 *
 * Parts are first compacted into rings (the WKT of the ring's points and whether it is clockwise),
 * which is all the assembly needs and takes a small fraction of the memory of the point arrays, so a
 * caller can compact each record as it is read and keep only the rings.
 */
final class ShapefilePolygonAssembler
{
    /**
     * @param  array<int, array{points: array<int, array{x: float, y: float}>}>  $parts
     */
    public static function toMultiPolygonWkt(array $parts): string
    {
        return self::toMultiPolygonWktFromRings(self::compactRings($parts));
    }

    /**
     * @param  array<int, array{points: array<int, array{x: float, y: float}>}>  $parts
     * @return array<int, array{wkt: string, clockwise: bool}>
     */
    public static function compactRings(array $parts): array
    {
        return array_map(function (array $part): array {
            $coordinates = [];

            foreach ($part['points'] as $point) {
                array_push($coordinates, $point['x'], $point['y']);
            }

            return self::ringFromCoordinates($coordinates);
        }, $parts);
    }

    /**
     * One ring from its flat coordinates, x then y for each point in turn: the ring's WKT and whether
     * it runs clockwise.
     *
     * @param  array<int, float>  $coordinates  zero-indexed
     * @return array{wkt: string, clockwise: bool}
     */
    public static function ringFromCoordinates(array $coordinates): array
    {
        $count = intdiv(count($coordinates), 2);
        $points = [];
        $sum = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $x = $coordinates[2 * $i];
            $y = $coordinates[2 * $i + 1];
            $next = ($i + 1) % $count;

            $sum += ($coordinates[2 * $next] - $x) * ($coordinates[2 * $next + 1] + $y);
            $points[] = self::formatNumber($x).' '.self::formatNumber($y);
        }

        return ['wkt' => implode(',', $points), 'clockwise' => $sum > 0];
    }

    /**
     * @param  array<int, array{wkt: string, clockwise: bool}>  $rings
     */
    public static function toMultiPolygonWktFromRings(array $rings): string
    {
        $polygons = [];
        $current = null;

        foreach ($rings as $ring) {
            if ($current === null || $ring['clockwise']) {
                if ($current !== null) {
                    $polygons[] = $current;
                }
                $current = [$ring['wkt']];
            } else {
                $current[] = $ring['wkt'];
            }
        }

        if ($current !== null) {
            $polygons[] = $current;
        }

        return 'MULTIPOLYGON('.implode(',', array_map(
            fn (array $wkts): string => '('.implode(',', array_map(fn (string $wkt): string => '('.$wkt.')', $wkts)).')',
            $polygons,
        )).')';
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(sprintf('%.7f', $value), '0'), '.') ?: '0';
    }
}
