<?php

use App\Actions\Shapefiles\ApplyRecordOverrides;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

test('it applies a standing override onto the live table by natural key', function () {
    DB::insert(
        'INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'NOAA Default Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => User::factory(),
    ]);

    $applied = (new ApplyRecordOverrides)($layer, ShapefileLayerDefinitions::forKey('public_zones'));

    expect($applied)->toBe(1)
        ->and(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin-Corrected Name');
});

test('it applies a composite-key override correctly', function () {
    DB::insert(
        'INSERT INTO zone_county_correlations (state, zone, cwa, name, state_zone, county, fips, lat, lon, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
        ['NM', '201', 'ABQ', 'Northwest Plateau', 'NM201', 'McKinley', '35031', 36.4, -108.5],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'zone_county_correlation']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201|35031',
        'field' => 'county',
        'value' => 'Corrected County',
        'created_by' => User::factory(),
    ]);

    (new ApplyRecordOverrides)($layer, ShapefileLayerDefinitions::forKey('zone_county_correlation'));

    expect(DB::table('zone_county_correlations')->where('state_zone', 'NM201')->value('county'))->toBe('Corrected County');
});
