<?php

use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;

test('a layer has many versions and an active version', function () {
    $layer = ShapefileLayer::factory()->create();
    $version = ShapefileLayerVersion::factory()->for($layer, 'shapefileLayer')->create();
    $layer->update(['active_version_id' => $version->id]);

    expect($layer->versions)->toHaveCount(1)
        ->and($layer->fresh()->activeVersion->id)->toBe($version->id);
});
