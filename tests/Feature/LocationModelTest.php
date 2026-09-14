<?php

use App\Enums\LocationType;
use App\Models\Location;

test('a stationary location with lat/lng set has a fix', function () {
    $location = Location::factory()->create();

    expect($location->hasFix())->toBeTrue();
});

test('a mobile location created via the mobile() factory state has no fix yet', function () {
    $location = Location::factory()->mobile()->create();

    expect($location->type)->toBe(LocationType::Mobile);
    expect($location->lat)->toBeNull();
    expect($location->lng)->toBeNull();
    expect($location->hasFix())->toBeFalse();
});

test('a mobile location given a fix via withFix() has one', function () {
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create();

    expect($location->hasFix())->toBeTrue();
    expect((float) $location->lat)->toBe(39.0997);
    expect((float) $location->lng)->toBe(-94.5786);
});
