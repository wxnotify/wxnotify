<?php

use App\Support\Shapefiles\ShapefileLayerDefinitions;

test('there are exactly 9 layer definitions with unique keys', function () {
    $definitions = ShapefileLayerDefinitions::all();

    expect($definitions)->toHaveCount(9)
        ->and(collect($definitions)->pluck('key')->unique())->toHaveCount(9);
});

test('the 3 marine sub-layers each have their own table pair and distinct zone type', function () {
    $marine = collect(ShapefileLayerDefinitions::all())->filter(
        fn ($d) => str_starts_with($d->key, 'marine_zones_')
    );

    expect($marine)->toHaveCount(3)
        ->and($marine->pluck('liveTable')->unique())->toHaveCount(3)
        ->and($marine->pluck('zoneType')->unique()->sort()->values()->all())
        ->toBe(['coastal', 'high_seas', 'offshore']);
});

test('forKey resolves a known key and throws for an unknown one', function () {
    expect(ShapefileLayerDefinitions::forKey('public_zones')->name)->toBe('Public Zones');

    ShapefileLayerDefinitions::forKey('does_not_exist');
})->throws(InvalidArgumentException::class);

test('serializeNaturalKey joins composite keys with a pipe', function () {
    $definition = ShapefileLayerDefinitions::forKey('zone_county_correlation');

    expect($definition->serializeNaturalKey(['state_zone' => 'NM201', 'fips' => '35031']))
        ->toBe('NM201|35031');
});

test('every definition has a real, existing model class', function () {
    foreach (ShapefileLayerDefinitions::all() as $definition) {
        expect(class_exists($definition->modelClass))->toBeTrue("Missing model class for {$definition->key}: {$definition->modelClass}");
    }
});

test('High Seas has its own real attribute schema, distinct from Coastal/Offshore, keyed on name not zone_id', function () {
    // High Seas' real DBF has no usable ID field at all (verified against a real
    // download: the closest field, lowercase `id`, is empty for every record) --
    // unlike Coastal/Offshore, which have a real, populated, unique `ID` field.
    $highSeas = ShapefileLayerDefinitions::forKey('marine_zones_high_seas');
    $coastal = ShapefileLayerDefinitions::forKey('marine_zones_coastal');

    expect($highSeas->naturalKeyColumns)->toBe(['name'])
        ->and($coastal->naturalKeyColumns)->toBe(['zone_id'])
        ->and($highSeas->attributeMap)->not->toBe($coastal->attributeMap)
        ->and($highSeas->attributeMap)->toHaveKey('id')
        ->and($highSeas->attributeMap)->not->toHaveKey('ID')
        ->and($highSeas->attributeMap)->not->toHaveKey('GL_WFO');
});
