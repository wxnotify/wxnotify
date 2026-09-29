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

test('$onStep is narrated for a new version, including the "fetched" and "latest available" steps', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones', 'source_url' => 'https://www.weather.gov/gis/PublicZones']);
    $steps = [];

    (new ScanShapefileLayer)($layer, function (string $message) use (&$steps): void {
        $steps[] = $message;
    });

    expect($steps)->toBe([
        'Fetched https://www.weather.gov/gis/PublicZones',
        'Latest available version: z_16ap26.zip (effective 2026-04-16 00:00:00)',
        'New version detected — staging it',
    ]);
});

test('$onStep reports "no change" rather than staying silent, when the checksum has not changed', function () {
    $html = file_get_contents(base_path('tests/Fixtures/awips-shapefiles/public-zones-page.html'));
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response($html)]);
    $layer = ShapefileLayer::factory()->create([
        'key' => 'public_zones',
        'source_url' => 'https://www.weather.gov/gis/PublicZones',
        'last_seen_checksum' => '004dc6501dc3d50e7b36652cb9d02bd3',
    ]);
    $steps = [];

    (new ScanShapefileLayer)($layer, function (string $message) use (&$steps): void {
        $steps[] = $message;
    });

    expect($steps)->toBe([
        'Fetched https://www.weather.gov/gis/PublicZones',
        'Latest available version: z_16ap26.zip (effective 2026-04-16 00:00:00)',
        'No change since the last scan',
    ]);
});

test('$onStep reports the HTTP failure instead of staying silent, distinguishing it from "no change"', function () {
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response('', 500)]);
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones', 'source_url' => 'https://www.weather.gov/gis/PublicZones']);
    $steps = [];

    $version = (new ScanShapefileLayer)($layer, function (string $message) use (&$steps): void {
        $steps[] = $message;
    });

    expect($version)->toBeNull()
        ->and($steps)->toBe(['Failed to fetch https://www.weather.gov/gis/PublicZones (HTTP 500)']);
});

test('$onStep reports when the page has no matching version group, instead of staying silent', function () {
    Http::fake(['www.weather.gov/gis/PublicZones' => Http::response('<table><tbody></tbody></table>')]);
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones', 'source_url' => 'https://www.weather.gov/gis/PublicZones']);
    $steps = [];

    $version = (new ScanShapefileLayer)($layer, function (string $message) use (&$steps): void {
        $steps[] = $message;
    });

    expect($version)->toBeNull()
        ->and($steps)->toBe([
            'Fetched https://www.weather.gov/gis/PublicZones',
            'No matching version group found on the page for this layer',
        ]);
});
