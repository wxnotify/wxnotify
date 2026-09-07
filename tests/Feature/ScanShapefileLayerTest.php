<?php

use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use Illuminate\Support\Facades\Http;

test('it stages a new version when the checksum changes', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'public_zones',
        'source_url' => 'https://www.weather.gov/gis/PublicZones',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->not->toBeNull()
        ->and($version->filename)->toBe('z_16ap26.zip')
        ->and($version->checksum)->toBe('004dc6501dc3d50e7b36652cb9d02bd3')
        ->and($version->status)->toBe(ShapefileVersionStatus::Staged);

    expect($layer->fresh()->last_seen_checksum)->toBe('004dc6501dc3d50e7b36652cb9d02bd3');
});

test('it does nothing when the checksum has not changed', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'public_zones',
        'source_url' => 'https://www.weather.gov/gis/PublicZones',
        'last_seen_checksum' => '004dc6501dc3d50e7b36652cb9d02bd3',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->toBeNull();
    expect($layer->versions()->count())->toBe(0);
});

test('the marine 3-group page yields the correct group for each sub-layer', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/marine-zones-page.html'));
    Http::fake(['www.weather.gov/gis/MarineZones' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'marine_zones_high_seas',
        'source_url' => 'https://www.weather.gov/gis/MarineZones',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version->filename)->toBe('hz17fe26.zip')
        ->and($version->record_count)->toBe(5);
});

test('it keys change detection on filename alone for the correlation layer', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/zone-county-page.html'));
    Http::fake(['www.weather.gov/gis/ZoneCounty' => Http::response($html)]);

    $layer = ShapefileLayer::factory()->create([
        'key' => 'zone_county_correlation',
        'source_url' => 'https://www.weather.gov/gis/ZoneCounty',
        'last_seen_filename' => 'bp16ap26.dbx',
    ]);

    $version = (new ScanShapefileLayer)($layer);

    expect($version)->toBeNull();
});
