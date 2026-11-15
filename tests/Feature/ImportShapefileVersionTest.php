<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

function buildFixtureZip(string $basename): string
{
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $record = new ShapeRecord(ShapeType::Polygon);
    $record->addPoint(['x' => 0, 'y' => 0], 0);
    $record->addPoint(['x' => 0, 'y' => 1], 0);
    $record->addPoint(['x' => 1, 'y' => 1], 0);
    $record->addPoint(['x' => 1, 'y' => 0], 0);
    $record->addPoint(['x' => 0, 'y' => 0], 0);
    $shp->addRecord($record);

    $record2 = new ShapeRecord(ShapeType::Polygon);
    $record2->addPoint(['x' => 5, 'y' => 5], 0);
    $record2->addPoint(['x' => 5, 'y' => 6], 0);
    $record2->addPoint(['x' => 6, 'y' => 6], 0);
    $record2->addPoint(['x' => 6, 'y' => 5], 0);
    $record2->addPoint(['x' => 5, 'y' => 5], 0);
    $shp->addRecord($record2);

    $shp->setDBFHeader([
        ['STATE', 'C', 2, 0], ['CWA', 'C', 9, 0], ['TIME_ZONE', 'C', 2, 0],
        ['FE_AREA', 'C', 2, 0], ['ZONE', 'C', 3, 0], ['NAME', 'C', 254, 0],
        ['STATE_ZONE', 'C', 5, 0], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7],
        ['SHORTNAME', 'C', 32, 0],
    ]);

    $shp->records[0]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '201', 'NAME' => 'Test Zone One', 'STATE_ZONE' => 'NM201',
        'LON' => -108.5, 'LAT' => 36.4, 'SHORTNAME' => 'Zone One',
    ];
    $shp->records[1]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '202', 'NAME' => 'Test Zone Two', 'STATE_ZONE' => 'NM202',
        'LON' => -108.9, 'LAT' => 36.0, 'SHORTNAME' => 'Zone Two',
    ];

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

test('it imports a real small shapefile fixture into the staging table', function () {
    $zipPath = buildFixtureZip('z_test01');
    Http::fake(['weather.gov/fixture.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('public_zones_staging')->count())->toBe(2);

    $zoneOne = DB::table('public_zones_staging')->where('state_zone', 'NM201')->first();
    expect($zoneOne)->not->toBeNull()
        ->and($zoneOne->name)->toBe('Test Zone One')
        ->and($zoneOne->cwa)->toBe('ABQ');

    $geometry = DB::selectOne('SELECT ST_AsText(boundary) as wkt FROM public_zones_staging WHERE state_zone = ?', ['NM201']);
    expect($geometry->wkt)->toContain('MULTIPOLYGON');
});

test('re-importing replaces the whole staging table (each layer, including each Marine sub-layer, has its own physical table)', function () {
    DB::insert(
        'INSERT INTO public_zones_staging (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['AZ', 'PSR', '001', 'Stale Zone', 'AZ001', 0, 0, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $zipPath = buildFixtureZip('z_test03');
    Http::fake(['weather.gov/fixture3.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture3.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('public_zones_staging')->count())->toBe(2)
        ->and(DB::table('public_zones_staging')->where('state_zone', 'AZ001')->exists())->toBeFalse();
});

test('it stores the computed diff summary on the version after importing', function () {
    $zipPath = buildFixtureZip('z_test02');
    Http::fake(['weather.gov/fixture2.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture2.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect($version->fresh()->diff_summary)->toBe([
        'added' => 2, 'removed' => 0, 'changed' => 0, 'override_conflicts' => [],
    ]);
});

function buildSplitZoneFixtureZip(string $basename): string
{
    // NOAA sometimes stores one logical zone as two separate shapefile records
    // sharing the same STATE_ZONE and attributes but different, disjoint geometry
    // -- verified against a real download (77 of Public Zones' 4,157 records are
    // such splits, e.g. Calvert County, MD). This fixture reproduces that shape.
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $recordA = new ShapeRecord(ShapeType::Polygon);
    $recordA->addPoint(['x' => 0, 'y' => 0], 0);
    $recordA->addPoint(['x' => 0, 'y' => 1], 0);
    $recordA->addPoint(['x' => 1, 'y' => 1], 0);
    $recordA->addPoint(['x' => 1, 'y' => 0], 0);
    $recordA->addPoint(['x' => 0, 'y' => 0], 0);
    $shp->addRecord($recordA);

    $recordB = new ShapeRecord(ShapeType::Polygon);
    $recordB->addPoint(['x' => 5, 'y' => 5], 0);
    $recordB->addPoint(['x' => 5, 'y' => 6], 0);
    $recordB->addPoint(['x' => 6, 'y' => 6], 0);
    $recordB->addPoint(['x' => 6, 'y' => 5], 0);
    $recordB->addPoint(['x' => 5, 'y' => 5], 0);
    $shp->addRecord($recordB);

    $shp->setDBFHeader([
        ['STATE', 'C', 2, 0], ['CWA', 'C', 9, 0], ['TIME_ZONE', 'C', 2, 0],
        ['FE_AREA', 'C', 2, 0], ['ZONE', 'C', 3, 0], ['NAME', 'C', 254, 0],
        ['STATE_ZONE', 'C', 5, 0], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7],
        ['SHORTNAME', 'C', 32, 0],
    ]);

    $shp->records[0]->dbfData = [
        'STATE' => 'MD', 'CWA' => 'LWX', 'TIME_ZONE' => 'E', 'FE_AREA' => 'ss',
        'ZONE' => '018', 'NAME' => 'Calvert', 'STATE_ZONE' => 'MD018',
        'LON' => -76.48, 'LAT' => 38.38, 'SHORTNAME' => 'Calvert',
    ];
    $shp->records[1]->dbfData = [
        'STATE' => 'MD', 'CWA' => 'LWX', 'TIME_ZONE' => 'E', 'FE_AREA' => 'ss',
        'ZONE' => '018', 'NAME' => 'Calvert', 'STATE_ZONE' => 'MD018',
        'LON' => -76.57, 'LAT' => 38.55, 'SHORTNAME' => 'Calvert',
    ];

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

test('two shapefile records sharing the same natural key merge into one row with a combined MultiPolygon', function () {
    $zipPath = buildSplitZoneFixtureZip('z_split_test');
    Http::fake(['weather.gov/split-fixture.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/split-fixture.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    // One row, not two -- the unique constraint on state_zone would otherwise fail.
    expect(DB::table('public_zones_staging')->count())->toBe(1);

    $row = DB::table('public_zones_staging')->where('state_zone', 'MD018')->first();
    expect($row)->not->toBeNull()
        ->and($row->name)->toBe('Calvert');

    // The combined geometry contains both disjoint squares as separate polygons
    // within one MultiPolygon, not just one of them.
    $geometry = DB::selectOne('SELECT ST_AsText(boundary) as wkt FROM public_zones_staging WHERE state_zone = ?', ['MD018']);
    expect($geometry->wkt)->toBe('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)),((5 5,5 6,6 6,6 5,5 5)))');
});

test('it notifies every staff user, and no one else, once the diff is computed', function () {
    Notification::fake();

    $staff = User::factory()->create(['is_staff' => true]);
    $subscriber = User::factory()->create(['is_staff' => false]);

    $zipPath = buildFixtureZip('z_test04');
    Http::fake(['weather.gov/fixture4.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture4.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    Notification::assertSentTo($staff, ShapefileLayerUpdateAvailableNotification::class);
    Notification::assertNotSentTo($subscriber, ShapefileLayerUpdateAvailableNotification::class);
});

/**
 * A polygon shapefile written straight to disk, record by record (the library would hold every point in
 * memory to write it): $records zones of $points points each, with a dbf holding the public zone columns.
 */
function buildLargeFixtureZip(string $basename, int $records, int $points): string
{
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $body = fopen($base.'.shp', 'wb');
    fwrite($body, str_repeat("\0", 100));
    $shx = fopen($base.'.shx', 'wb');
    fwrite($shx, str_repeat("\0", 100));

    $offsetWords = 50;
    for ($record = 1; $record <= $records; $record++) {
        $ring = '';
        for ($i = 0; $i < $points; $i++) {
            $angle = -2 * M_PI * $i / ($points - 1);
            $ring .= pack('dd', 100 + $record + cos($angle), 30 + sin($angle));
        }
        $content = pack('V', 5).pack('dddd', 99, 29, 200, 31).pack('VV', 1, $points).pack('V', 0).$ring;
        $contentWords = intdiv(strlen($content), 2);
        fwrite($body, pack('NN', $record, $contentWords).$content);
        fwrite($shx, pack('NN', $offsetWords, $contentWords));
        $offsetWords += 4 + $contentWords;
    }

    $header = fn (int $lengthWords): string => pack('N', 9994).str_repeat("\0", 20).pack('N', $lengthWords).pack('V', 1000).pack('V', 5).pack('dddd', 99, 29, 200, 31).str_repeat("\0", 32);
    fseek($body, 0);
    fwrite($body, $header($offsetWords));
    fseek($shx, 0);
    fwrite($shx, $header(50 + 4 * $records));
    fclose($body);
    fclose($shx);

    $db = dbase_create($base.'.dbf', [
        ['STATE', 'C', 2], ['CWA', 'C', 9], ['TIME_ZONE', 'C', 2], ['FE_AREA', 'C', 2], ['ZONE', 'C', 3],
        ['NAME', 'C', 40], ['STATE_ZONE', 'C', 5], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7], ['SHORTNAME', 'C', 32],
    ]);
    for ($record = 1; $record <= $records; $record++) {
        $zone = str_pad((string) $record, 3, '0', STR_PAD_LEFT);
        dbase_add_record($db, ['NM', 'ABQ', 'M', 'nw', $zone, 'Zone '.$record, 'NM'.$zone, -100.5, 30.5, 'Z'.$record]);
    }
    dbase_close($db);

    $zipPath = $dir.'/'.$basename.'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    foreach (['shp', 'shx', 'dbf'] as $ext) {
        $zip->addFile($base.'.'.$ext, $basename.'.'.$ext);
    }
    $zip->close();

    return $zipPath;
}

test('importing a large layer holds only one record\'s geometry at a time', function () {
    // 150 zones x 4,000 points: loading all of it as PHP arrays takes several hundred MB
    // (the 256 MB limit the published containers run with kills it); one record at a time is a few MB.
    $zipPath = buildLargeFixtureZip('z_large01', 150, 4000);
    Http::fake(['weather.gov/large.zip' => Http::response(file_get_contents($zipPath))]);

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/large.zip',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    (new ImportShapefileVersion)($version);

    $growthMb = (memory_get_peak_usage() - $before) / 1048576;

    File::deleteDirectory(storage_path('app/test-fixtures/z_large01'));

    expect(DB::table('public_zones_staging')->count())->toBe(150)
        ->and($growthMb)->toBeLessThan(60);
});
