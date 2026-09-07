<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Illuminate\Database\QueryException;

test('an override belongs to a layer and the staff user who created it', function () {
    $layer = ShapefileLayer::factory()->create();
    $user = User::factory()->create();

    $override = ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
        'value' => 'Corrected Zone Name',
        'created_by' => $user->id,
    ]);

    expect($override->shapefileLayer->id)->toBe($layer->id)
        ->and($override->createdBy->id)->toBe($user->id);
});

test('the same field cannot be overridden twice for the same record on the same layer', function () {
    $layer = ShapefileLayer::factory()->create();
    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
    ]);

    ShapefileRecordOverride::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'record_key' => 'NM201',
        'field' => 'name',
    ]);
})->throws(QueryException::class);
