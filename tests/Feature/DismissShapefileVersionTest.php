<?php

use App\Actions\Shapefiles\DismissShapefileVersion;
use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;

test('it marks a staged version rejected without touching the layer\'s active version', function () {
    $layer = ShapefileLayer::factory()->create(['active_version_id' => null]);
    $version = ShapefileLayerVersion::factory()->create([
        'shapefile_layer_id' => $layer->id,
        'status' => ShapefileVersionStatus::Staged,
    ]);

    (new DismissShapefileVersion)($version);

    expect($version->fresh()->status)->toBe(ShapefileVersionStatus::Rejected)
        ->and($layer->fresh()->active_version_id)->toBeNull();
});
