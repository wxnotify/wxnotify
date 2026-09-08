<?php

use App\Actions\Weather\ResolveLocationZones;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

function insertTestZonePolygon(string $table, array $attributes, string $wkt): void
{
    DB::table($table)->insert([
        ...$attributes,
        'lon' => 0, 'lat' => 0,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('{$wkt}')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

beforeEach(function () {
    // A 1x1-degree square roughly over eastern New York, real enough to
    // contain a test point without colliding with any other fixture.
    insertTestZonePolygon('public_zones', [
        'state' => 'NY', 'cwa' => 'ALY', 'zone' => '001', 'name' => 'Test Zone',
        'state_zone' => 'NY001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');

    insertTestZonePolygon('counties', [
        'state' => 'NY', 'cwa' => 'ALY', 'countyname' => 'Test County', 'fips' => '36001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');

    insertTestZonePolygon('fire_zones', [
        'state' => 'NY', 'cwa' => 'ALY', 'zone' => '001', 'name' => 'Test Fire Zone',
        'state_zone' => 'NY001',
    ], 'POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))');
});

test('a location inside the fixture polygons resolves all three UGC codes', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);

    (new ResolveLocationZones)($location);

    expect($location->forecast_zone_ugc)->toBe('NYZ001')
        ->and($location->county_ugc)->toBe('NYC001')
        ->and($location->fire_weather_zone_ugc)->toBe('NYZ001');
});

test('a location outside every fixture polygon resolves all three codes to null', function () {
    $location = Location::factory()->create(['lat' => 0.0, 'lng' => 0.0]);

    (new ResolveLocationZones)($location);

    expect($location->forecast_zone_ugc)->toBeNull()
        ->and($location->county_ugc)->toBeNull()
        ->and($location->fire_weather_zone_ugc)->toBeNull();
});

test('the resolved codes are persisted, not just set on the in-memory model', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);

    (new ResolveLocationZones)($location);

    expect($location->fresh()->county_ugc)->toBe('NYC001');
});

test('changing a location\'s coordinates invalidates its previously resolved zones', function () {
    $location = Location::factory()->create(['lat' => 42.5, 'lng' => -73.5]);
    (new ResolveLocationZones)($location);
    expect($location->fresh()->county_ugc)->toBe('NYC001');

    $location->update(['lat' => 0.0, 'lng' => 0.0]);

    expect($location->fresh()->county_ugc)->toBeNull();
});
