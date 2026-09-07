<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Actions\Shapefiles\RollbackShapefileLayer;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Support\Facades\DB;

test('rolling back restores the previous version and demotes the current one', function () {
    DB::insert(
        'INSERT INTO us_states_staging (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'New Mexico v1', '35', -106.0, 34.5, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $versionOne = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);
    (new ActivateShapefileVersion)($versionOne);

    DB::insert(
        'INSERT INTO us_states_staging (state, name, fips, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'New Mexico v2', '35', -106.0, 34.5, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
    $versionTwo = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);
    (new ActivateShapefileVersion)($versionTwo);

    expect(DB::table('us_states')->value('name'))->toBe('New Mexico v2');

    (new RollbackShapefileLayer)($layer->fresh());

    expect(DB::table('us_states')->value('name'))->toBe('New Mexico v1')
        ->and($versionOne->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($versionTwo->fresh()->status)->toBe(ShapefileVersionStatus::Superseded)
        ->and($layer->fresh()->active_version_id)->toBe($versionOne->id);
});

test('rolling back with nothing superseded does nothing', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'counties']);

    (new RollbackShapefileLayer)($layer);

    expect($layer->fresh()->active_version_id)->toBeNull();
});
