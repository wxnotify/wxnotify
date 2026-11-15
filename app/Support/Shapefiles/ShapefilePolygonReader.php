<?php

namespace App\Support\Shapefiles;

use Generator;
use RuntimeException;

/**
 * Reads the polygon records of a .shp file one at a time, straight from the binary layout, into the
 * rings ShapefilePolygonAssembler works with. The phpmyadmin/shapefile library keeps every point of
 * every record as PHP arrays (about 400 bytes a point), which for NOAA's boundary layers means well
 * over a gigabyte (one High Seas record alone has 535,000 points); here a record's points are
 * unpacked a ring at a time and kept only as WKT text, so a record costs a few times its size on disk.
 * Attributes (the .dbf) are not read here.
 *
 * Supports Polygon, PolygonM and PolygonZ records (the x/y points come first in all three) and null
 * shapes (a record with no rings); any other shape type is an error.
 */
final class ShapefilePolygonReader
{
    private const FILE_CODE = 9994;

    private const SHAPE_NULL = 0;

    private const SHAPE_POLYGONS = [5, 15, 25];

    /** @var resource */
    private $stream;

    /**
     * @param  resource  $stream
     */
    private function __construct($stream)
    {
        $this->stream = $stream;
    }

    public static function open(string $shpPath): self
    {
        $handle = @fopen($shpPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open the shapefile {$shpPath}");
        }

        $header = fread($handle, 100);

        if ($header === false || strlen($header) < 100 || unpack('N', $header)[1] !== self::FILE_CODE) {
            fclose($handle);

            throw new RuntimeException("{$shpPath} is not a shapefile (file code mismatch)");
        }

        return new self($handle);
    }

    /**
     * @return Generator<int, array<int, array{wkt: string, clockwise: bool}>> The rings of each record, keyed by its record number.
     */
    public function records(): Generator
    {
        while (($header = fread($this->stream, 8)) !== false && strlen($header) === 8) {
            ['number' => $number, 'words' => $words] = unpack('Nnumber/Nwords', $header);

            $content = fread($this->stream, $words * 2);

            if ($content === false || strlen($content) !== $words * 2) {
                throw new RuntimeException("Shapefile record {$number} is truncated.");
            }

            yield $number => $this->ringsOf($number, $content);
        }
    }

    public function close(): void
    {
        fclose($this->stream);
    }

    /**
     * @return array<int, array{wkt: string, clockwise: bool}>
     */
    private function ringsOf(int $number, string $content): array
    {
        $type = unpack('V', $content)[1];

        if ($type === self::SHAPE_NULL) {
            return [];
        }

        if (! in_array($type, self::SHAPE_POLYGONS, true)) {
            throw new RuntimeException("Shapefile record {$number} has shape type {$type}, not a polygon.");
        }

        // type (4), bounding box (32), part count (4), point count (4), then the part start indexes and the points.
        ['parts' => $partCount, 'points' => $pointCount] = unpack('Vparts/Vpoints', $content, 36);

        $starts = array_values(unpack("V{$partCount}", $content, 44));
        $pointsAt = 44 + 4 * $partCount;

        $rings = [];

        foreach ($starts as $index => $start) {
            $end = $starts[$index + 1] ?? $pointCount;
            $coordinates = unpack('d*', substr($content, $pointsAt + 16 * $start, 16 * ($end - $start)));

            $rings[] = ShapefilePolygonAssembler::ringFromCoordinates(array_values($coordinates));
        }

        return $rings;
    }
}
