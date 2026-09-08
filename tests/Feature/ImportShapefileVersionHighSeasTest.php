<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

function buildHighSeasFixtureZip(string $basename): string
{
    // Reproduces High Seas Marine Zones' real DBF schema (verified against a real
    // download): WFO, NAME, LAT, LON, a lowercase `id` field that's empty for
    // every real record, and a URL field this app doesn't map. No usable ID
    // field exists at all -- this is what originally caused all 5 real records
    // to collapse into 1 (they all shared the same empty natural key).
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $coords = [
        [0, 0], [10, 0], [20, 0], [30, 0], [40, 0],
    ];

    foreach ($coords as $i => [$x, $y]) {
        $record = new ShapeRecord(ShapeType::Polygon);
        $record->addPoint(['x' => $x, 'y' => $y], 0);
        $record->addPoint(['x' => $x, 'y' => $y + 1], 0);
        $record->addPoint(['x' => $x + 1, 'y' => $y + 1], 0);
        $record->addPoint(['x' => $x + 1, 'y' => $y], 0);
        $record->addPoint(['x' => $x, 'y' => $y], 0);
        $shp->addRecord($record);
    }

    $shp->setDBFHeader([
        ['WFO', 'C', 3, 0], ['NAME', 'C', 250, 0], ['LAT', 'N', 19, 11],
        ['LON', 'N', 19, 11], ['id', 'C', 5, 0], ['URL', 'C', 254, 0],
    ]);

    $names = [
        'North Atlantic Ocean between 31N and 67N latitude',
        'Atlantic Ocean West of 35W longitude between 31N and 7N latitude',
        'Eastern North Pacific Ocean between the Equator and 30N latitude',
        'North Pacific Ocean between 30N and the Bering Strait',
        'Central North Pacific Ocean between the Equator and 30N latitude',
    ];
    $wfos = ['OPC', 'NHC', 'NHC', 'OPC', 'HPA'];

    foreach ($names as $i => $name) {
        $shp->records[$i]->dbfData = [
            'WFO' => $wfos[$i], 'NAME' => $name, 'LAT' => 40.0 + $i, 'LON' => -60.0 - $i,
            'id' => '', 'URL' => 'http://tgftp.nws.noaa.gov/data/raw/fz/example.txt',
        ];
    }

    $shp->saveToFile($base.'.*');

    $zipPath = $dir.'/'.$basename.'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['shp', 'shx', 'dbf'] as $ext) {
        $zip->addFile($base.'.'.$ext, $basename.'.'.$ext);
    }
    $zip->close();

    return $zipPath;
}

test('5 High Seas records with distinct names but empty id fields import as 5 separate rows, not 1', function () {
    $zipPath = buildHighSeasFixtureZip('hz_test01');
    Http::fake(['weather.gov/high-seas-fixture.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'marine_zones_high_seas']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/high-seas-fixture.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    // This is the exact bug: before the fix, all 5 records shared the same
    // (empty) zone_id natural key and collapsed into 1 row.
    expect(DB::table('marine_zones_high_seas_staging')->count())->toBe(5);

    $row = DB::table('marine_zones_high_seas_staging')
        ->where('name', 'North Pacific Ocean between 30N and the Bering Strait')
        ->first();
    expect($row)->not->toBeNull()
        ->and($row->wfo)->toBe('OPC');
});
