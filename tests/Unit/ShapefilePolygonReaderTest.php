<?php

use App\Support\Shapefiles\ShapefilePolygonAssembler;
use App\Support\Shapefiles\ShapefilePolygonReader;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

/**
 * @return string The base path (no extension) of a small polygon shapefile whose middle record has a hole.
 */
function smallShapefileBase(): string
{
    $dir = sys_get_temp_dir().'/polygon-reader-'.uniqid();
    mkdir($dir, 0777, true);
    $base = $dir.'/small';

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 30, 'ymax' => 30]);

    foreach ([[0, 0], [10, 10], [20, 20]] as $index => [$x, $y]) {
        $record = new ShapeRecord(ShapeType::Polygon);
        foreach ([[$x, $y], [$x, $y + 5], [$x + 5, $y + 5], [$x + 5, $y], [$x, $y]] as $point) {
            $record->addPoint(['x' => $point[0], 'y' => $point[1]], 0);
        }

        if ($index === 1) {
            foreach ([[$x + 1, $y + 1], [$x + 4, $y + 1], [$x + 4, $y + 4], [$x + 1, $y + 4], [$x + 1, $y + 1]] as $point) {
                $record->addPoint(['x' => $point[0] + 0.1234567891, 'y' => $point[1]], 1);
            }
        }

        $shp->addRecord($record);
    }

    $shp->setDBFHeader([['NAME', 'C', 10, 0]]);
    foreach ($shp->records as $index => $record) {
        $record->dbfData = ['NAME' => 'Zone '.$index];
    }
    $shp->saveToFile($base.'.*');

    return $base;
}

function removeShapefileBase(string $base): void
{
    array_map('unlink', glob(dirname($base).'/*'));
    rmdir(dirname($base));
}

test('it reads the same rings, in order, as the library parses from the same file', function () {
    $base = smallShapefileBase();

    $loaded = new ShapeFile(ShapeType::Polygon);
    $loaded->loadFromFile($base.'.*');
    $expected = [];
    foreach ($loaded->records as $record) {
        $expected[$record->recordNumber] = ShapefilePolygonAssembler::compactRings($record->shpData['parts']);
    }

    $reader = ShapefilePolygonReader::open($base.'.shp');
    $actual = iterator_to_array($reader->records());
    $reader->close();
    removeShapefileBase($base);

    expect(array_keys($actual))->toBe([1, 2, 3])
        ->and($actual)->toBe($expected)
        ->and($actual[2])->toHaveCount(2)
        ->and($actual[2][1]['clockwise'])->toBeFalse();
});

test('it refuses a file that is not a shapefile', function () {
    $path = sys_get_temp_dir().'/not-a-shapefile-'.uniqid().'.shp';
    file_put_contents($path, str_repeat('x', 200));

    expect(fn () => ShapefilePolygonReader::open($path))->toThrow(RuntimeException::class, 'not a shapefile');

    unlink($path);
});

test('it reports a record cut short instead of importing half of it', function () {
    $base = smallShapefileBase();
    file_put_contents($base.'.shp', substr(file_get_contents($base.'.shp'), 0, -40));

    $reader = ShapefilePolygonReader::open($base.'.shp');

    expect(fn () => iterator_to_array($reader->records()))->toThrow(RuntimeException::class, 'truncated');

    $reader->close();
    removeShapefileBase($base);
});

test('it refuses a record that is not a polygon', function () {
    $base = smallShapefileBase();
    $bytes = file_get_contents($base.'.shp');
    // The first record's shape type sits right after the 100-byte header and the 8-byte record header.
    file_put_contents($base.'.shp', substr_replace($bytes, pack('V', 3), 108, 4));

    $reader = ShapefilePolygonReader::open($base.'.shp');

    expect(fn () => iterator_to_array($reader->records()))->toThrow(RuntimeException::class, 'not a polygon');

    $reader->close();
    removeShapefileBase($base);
});
