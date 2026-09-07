<?php

use App\Actions\Shapefiles\ActivateShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('activating a staged version swaps staging into live and applies standing overrides', function () {
    DB::insert(
        'INSERT INTO public_zones (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'Old Live Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );
    DB::insert(
        'INSERT INTO public_zones_staging (state, cwa, zone, name, state_zone, lon, lat, boundary, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ST_SetSRID(ST_GeomFromText(?), 4269), now(), now())',
        ['NM', 'ABQ', '201', 'New Staged Name', 'NM201', -108.5, 36.4, 'MULTIPOLYGON(((0 0,0 1,1 1,1 0,0 0)))'],
    );

    $layer = ShapefileLayer::factory()->create(['key' => 'public_zones']);
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Admin Override Name',
        'created_by' => User::factory(),
    ]);

    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($version);

    expect(DB::table('public_zones')->where('state_zone', 'NM201')->value('name'))->toBe('Admin Override Name')
        ->and(DB::table('public_zones_staging')->where('state_zone', 'NM201')->value('name'))->toBe('Old Live Name')
        ->and($version->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($version->fresh()->activated_at)->not->toBeNull()
        ->and($layer->fresh()->active_version_id)->toBe($version->id);
});

test('activating supersedes the previously active version', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'us_states']);
    $oldVersion = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Active,
    ]);
    $layer->update(['active_version_id' => $oldVersion->id]);

    $newVersion = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($newVersion);

    expect($oldVersion->fresh()->status)->toBe(ShapefileVersionStatus::Superseded)
        ->and($newVersion->fresh()->status)->toBe(ShapefileVersionStatus::Active)
        ->and($layer->fresh()->active_version_id)->toBe($newVersion->id);
});

test('a first-ever activation with no previous active version does not error', function () {
    $layer = ShapefileLayer::factory()->create(['key' => 'cwa_boundaries']);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new ActivateShapefileVersion)($version);

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Active);
});
