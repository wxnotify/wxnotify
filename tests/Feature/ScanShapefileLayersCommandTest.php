<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Database\Seeders\ShapefileLayerSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

test('it scans every seeded layer and reports how many staged a new version', function () {
    (new ShapefileLayerSeeder)->run();

    Http::fake(fn () => Http::response('<table><tbody><tr><td>x</td></tr></tbody></table>'));

    $this->artisan('shapefile:scan')
        ->assertSuccessful();

    expect(ShapefileLayer::count())->toBe(9);
    Http::assertSentCount(9);
});

function buildScanCommandFixtureZip(string $basename): string
{
    $dir = storage_path('app/test-fixtures/'.$basename);
    File::ensureDirectoryExists($dir);
    $base = $dir.'/'.$basename;

    $shp = new ShapeFile(ShapeType::Polygon, ['xmin' => 0, 'ymin' => 0, 'xmax' => 10, 'ymax' => 10]);

    $record = new ShapeRecord(ShapeType::Polygon);
    foreach ([[0, 0], [0, 1], [1, 1], [1, 0], [0, 0]] as [$x, $y]) {
        $record->addPoint(['x' => $x, 'y' => $y], 0);
    }
    $shp->addRecord($record);

    $shp->setDBFHeader([
        ['STATE', 'C', 2, 0], ['CWA', 'C', 9, 0], ['TIME_ZONE', 'C', 2, 0],
        ['FE_AREA', 'C', 2, 0], ['ZONE', 'C', 3, 0], ['NAME', 'C', 254, 0],
        ['STATE_ZONE', 'C', 5, 0], ['LON', 'N', 12, 7], ['LAT', 'N', 12, 7],
        ['SHORTNAME', 'C', 32, 0],
    ]);

    $shp->records[0]->dbfData = [
        'STATE' => 'NM', 'CWA' => 'ABQ', 'TIME_ZONE' => 'M', 'FE_AREA' => 'nw',
        'ZONE' => '201', 'NAME' => 'Command Test Zone', 'STATE_ZONE' => 'NM201',
        'LON' => -108.5, 'LAT' => 36.4, 'SHORTNAME' => 'Zone One',
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

test('it actually imports a newly staged version, not just stages it', function () {
    (new ShapefileLayerSeeder)->run();

    $zipPath = buildScanCommandFixtureZip('command_test01');
    $pageHtml = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));

    Http::fake([
        'www.weather.gov/gis/PublicZones' => Http::response($pageHtml),
        'www.weather.gov/source/gis/Shapefiles/WSOM/z_16ap26.zip' => Http::response(file_get_contents($zipPath)),
        '*' => Http::response('<table><tbody><tr><td>x</td></tr></tbody></table>'),
    ]);

    $this->artisan('shapefile:scan')->assertSuccessful();

    $layer = ShapefileLayer::where('key', 'public_zones')->firstOrFail();
    $version = ShapefileLayerVersion::where('shapefile_layer_id', $layer->id)->firstOrFail();

    // The version was staged...
    expect($version->filename)->toBe('z_16ap26.zip');

    // ...and the command also imported it into staging: real rows, not empty.
    expect(DB::table('public_zones_staging')->count())->toBe(1)
        ->and(DB::table('public_zones_staging')->value('name'))->toBe('Command Test Zone')
        ->and($version->fresh()->diff_summary)->not->toBeNull();
});
