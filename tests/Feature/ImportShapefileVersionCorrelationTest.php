<?php

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('it imports a real correlation-file fixture into the staging table', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n"
        .'NM|201|ABQ|Northwest Plateau|NM201|San Juan|35045|M|nw|36.4270|-108.4064';

    Http::fake(['weather.gov/fixture.dbx' => Http::response($content)]);

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.dbx',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('zone_county_correlations_staging')->count())->toBe(2);

    $row = DB::table('zone_county_correlations_staging')->where('county', 'McKinley')->first();
    expect($row)->not->toBeNull()
        ->and($row->state_zone)->toBe('NM201')
        ->and($row->fips)->toBe('35031');
});

test('duplicate zone-county pairs (same natural key, different centroid) keep only the first occurrence', function () {
    // Reproduces a real duplicate from NOAA's own correlation file: the same
    // zone-county overlap split into two disjoint areas, same state_zone/fips,
    // different lat/lon.
    $content = "VA|075|AKQ|Westmoreland|VA075|Westmoreland|51193|E|ec|38.1108|-76.8052\n"
        .'VA|075|AKQ|Westmoreland|VA075|Westmoreland|51193|E|ec|38.1366|-76.7253';

    Http::fake(['weather.gov/fixture.dbx' => Http::response($content)]);

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.dbx',
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ImportShapefileVersion)($version);

    // One row, not two -- the unique constraint on (state_zone, fips) would otherwise fail.
    expect(DB::table('zone_county_correlations_staging')->count())->toBe(1);

    $row = DB::table('zone_county_correlations_staging')->where('state_zone', 'VA075')->first();
    expect($row)->not->toBeNull()
        ->and($row->fips)->toBe('51193')
        ->and((float) $row->lat)->toBe(38.1108);
});

test('re-importing replaces the whole correlation staging table', function () {
    DB::table('zone_county_correlations_staging')->insert([
        'state' => 'AZ', 'zone' => '001', 'cwa' => 'PSR', 'name' => 'Stale', 'state_zone' => 'AZ001',
        'county' => 'Stale County', 'fips' => '04000', 'lat' => 0, 'lon' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    Http::fake(['weather.gov/fixture.dbx' => Http::response(
        'NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064'
    )]);

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'source_url' => 'https://www.weather.gov/fixture.dbx',
    ]);

    (new ImportShapefileVersion)($version);

    expect(DB::table('zone_county_correlations_staging')->count())->toBe(1)
        ->and(DB::table('zone_county_correlations_staging')->where('county', 'Stale County')->exists())->toBeFalse();
});
