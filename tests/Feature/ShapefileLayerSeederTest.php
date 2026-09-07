<?php

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Database\Seeders\ShapefileLayerSeeder;

test('it seeds exactly one shapefile_layers row per registry definition', function () {
    (new ShapefileLayerSeeder)->run();

    expect(ShapefileLayer::count())->toBe(9);

    foreach (ShapefileLayerDefinitions::all() as $definition) {
        $layer = ShapefileLayer::where('key', $definition->key)->first();

        expect($layer)->not->toBeNull()
            ->and($layer->source_url)->toBe($definition->sourceUrl)
            ->and($layer->name)->toBe($definition->name);
    }
});

test('running it twice does not duplicate rows', function () {
    (new ShapefileLayerSeeder)->run();
    (new ShapefileLayerSeeder)->run();

    expect(ShapefileLayer::count())->toBe(9);
});
