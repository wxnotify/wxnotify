<?php

use App\Models\ShapefileLayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('it returns a GeoJSON FeatureCollection for a shapefile-format layer', function () {
    DB::insert(
        'INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'Northwest Plateau', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $staff = User::factory()->create(['is_staff' => true]);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]));

    $response->assertSuccessful()
        ->assertJsonPath('type', 'FeatureCollection')
        ->assertJsonCount(1, 'features')
        ->assertJsonPath('features.0.geometry.type', 'MultiPolygon')
        ->assertJsonPath('features.0.properties.name', 'Northwest Plateau');
});

test('it returns an empty feature list for the non-geometry correlation layer', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    $staff = User::factory()->create(['is_staff' => true]);

    $response = $this->actingAs($staff)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]));

    $response->assertSuccessful()->assertJsonCount(0, 'features');
});

test('a non-staff user is forbidden', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $subscriber = User::factory()->create(['is_staff' => false]);

    $this->actingAs($subscriber)
        ->getJson(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $layer]))
        ->assertForbidden();
});
