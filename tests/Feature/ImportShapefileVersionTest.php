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
