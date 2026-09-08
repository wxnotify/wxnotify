<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\ApplyRecordOverrides;
use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Notifications\ShapefileLayerUpdateAvailableNotification;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Database\Seeders\ShapefileLayerSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PhpMyAdmin\ShapeFile\ShapeFile;
use PhpMyAdmin\ShapeFile\ShapeRecord;
use PhpMyAdmin\ShapeFile\ShapeType;

function buildPipelineFixtureZip(string $basename, string $zoneName): string
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
        'ZONE' => '201', 'NAME' => $zoneName, 'STATE_ZONE' => 'NM201',
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

test('the full scan-import-notify-activate cycle works, and an override survives a second cycle', function () {
    Notification::fake();
    (new ShapefileLayerSeeder)->run();
    $staff = User::factory()->create(['is_staff' => true]);
    $admin = User::factory()->create(['is_staff' => true]);

    // Cycle 1: NOAA publishes a version, it gets scanned, imported, and activated.
    $zipOne = buildPipelineFixtureZip('pipeline_v1', 'Northwest Plateau');
    $pageOne = '<table><tbody><tr>'
        .'<td colspan="1" rowspan="1">Public Forecast Zones</td>'
        .'<td>18 March 2025</td>'
        .'<td><a href="/source/gis/Shapefiles/WSOM/pipeline_v1.zip">pipeline_v1.zip</a></td>'
        .'<td>1</td>'
        .'<td>'.md5('v1').'<br>Date last upload: 1-JAN-25</td>'
        .'</tr></tbody></table>';

    // The public-zones page URL is scanned twice across the two cycles below, but
    // Http::fake() checks stubs in registration order and the first URL match wins
    // — a second Http::fake() call for the same URL would never take effect. Route
    // it through a mutable closure instead so each cycle can swap the page body.
    $currentPageHtml = $pageOne;

    Http::fake([
        'www.weather.gov/gis/PublicZones' => function () use (&$currentPageHtml) {
            return Http::response($currentPageHtml);
        },
        'www.weather.gov/source/gis/Shapefiles/WSOM/pipeline_v1.zip' => Http::response(file_get_contents($zipOne)),
    ]);

    $layer = ShapefileLayer::where('key', 'public_zones')->firstOrFail();
    $version = (new ScanShapefileLayer)($layer);
    expect($version)->not->toBeNull();

    (new ImportShapefileVersion)($version);
    expect(DB::table('public_zones_staging')->where('state_zone', 'NM201')->value('name'))->toBe('Northwest Plateau');

    Notification::assertSentTo($staff, ShapefileLayerUpdateAvailableNotification::class);

    (new ActivateShapefileVersion)($version);
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Northwest Plateau')
        ->and($version->fresh()->status)->toBe(ShapefileVersionStatus::Active);

    // Admin corrects the name via a standing override.
    ShapefileRecordOverride::create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => $admin->id,
    ]);
    app(ApplyRecordOverrides::class)($layer, ShapefileLayerDefinitions::forKey('public_zones'));
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');

    // Cycle 2: NOAA publishes ANOTHER new version with yet another raw name.
    // The diff must flag the conflict with the standing override, and activation
    // must reapply the override on top of the freshly-swapped-in NOAA data.
    $zipTwo = buildPipelineFixtureZip('pipeline_v2', 'NOAA Renamed It Again');
    $pageTwo = '<table><tbody><tr>'
        .'<td colspan="1" rowspan="1">Public Forecast Zones</td>'
        .'<td>16 April 2026</td>'
        .'<td><a href="/source/gis/Shapefiles/WSOM/pipeline_v2.zip">pipeline_v2.zip</a></td>'
        .'<td>1</td>'
        .'<td>'.md5('v2').'<br>Date last upload: 1-MAR-26</td>'
        .'</tr></tbody></table>';

    $currentPageHtml = $pageTwo;

    Http::fake([
        'www.weather.gov/source/gis/Shapefiles/WSOM/pipeline_v2.zip' => Http::response(file_get_contents($zipTwo)),
    ]);

    $versionTwo = (new ScanShapefileLayer)($layer->fresh());
    expect($versionTwo)->not->toBeNull();

    (new ImportShapefileVersion)($versionTwo);
    expect($versionTwo->fresh()->diff_summary['override_conflicts'])->toBe(['NM201']);

    (new ActivateShapefileVersion)($versionTwo);
    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');
});
