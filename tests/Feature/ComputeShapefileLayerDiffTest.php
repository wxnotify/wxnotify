<?php

use App\Actions\Shapefiles\ComputeShapefileLayerDiff;
use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Support\Facades\DB;

function insertZoneRow(string $table, string $stateZone, string $name, string $cwa = 'ABQ'): void
{
    DB::insert(
        "INSERT INTO {$table} (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())",
        ['NM', $cwa, '201', $name, $stateZone, -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
}

test('it counts added, removed, and changed records correctly', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $definition = ShapefileLayerDefinitions::forKey('public_zones');

    insertZoneRow('public_zones', 'NM201', 'Unchanged Zone');
    insertZoneRow('public_zones_staging', 'NM201', 'Unchanged Zone');

    insertZoneRow('public_zones', 'NM202', 'Old Name');
    insertZoneRow('public_zones_staging', 'NM202', 'New Name');

    insertZoneRow('public_zones', 'NM203', 'Removed Zone');

    insertZoneRow('public_zones_staging', 'NM204', 'Added Zone');

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['added'])->toBe(1)
        ->and($diff['removed'])->toBe(1)
        ->and($diff['changed'])->toBe(1)
        ->and($diff['override_conflicts'])->toBe([]);
});

test('it flags a standing override that disagrees with NOAA\'s new raw staged value', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    $definition = ShapefileLayerDefinitions::forKey('public_zones');
    $user = User::factory()->create();

    insertZoneRow('public_zones', 'NM201', 'Admin-Corrected Name');
    insertZoneRow('public_zones_staging', 'NM201', 'NOAA Renamed It Again');

    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin-Corrected Name',
        'created_by' => $user->id,
    ]);

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['override_conflicts'])->toBe(['NM201']);
});

test('a marine sub-layer diffs against its own dedicated table, independent of the other two', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'marine_zones_offshore']);
    $definition = ShapefileLayerDefinitions::forKey('marine_zones_offshore');

    DB::table('marine_zones_offshore')->insert([
        'zone_type' => 'offshore', 'zone_id' => 'OZ001', 'name' => 'Offshore Zone',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('marine_zones_offshore_staging')->insert([
        'zone_type' => 'offshore', 'zone_id' => 'OZ999', 'name' => 'Different Offshore Zone',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('marine_zones_coastal')->insert([
        'zone_type' => 'coastal', 'zone_id' => 'OZ001', 'name' => 'Coincidentally same zone_id, different table/layer',
        'lon' => 0, 'lat' => 0, 'boundary' => DB::raw("ST_SetSRID(ST_GeomFromText('MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $diff = (new ComputeShapefileLayerDiff)($layer, $definition);

    expect($diff['added'])->toBe(1)
        ->and($diff['removed'])->toBe(1);
});
