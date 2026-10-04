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

test('saving a location as stationary clears every Traccar and mobile field', function () {
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create([
        'traccar_device_id' => 7,
        'traccar_device_name' => 'Truck',
        'mobile_update_interval_minutes' => 15,
        'mobile_position_updated_at' => now(),
    ]);

    $location->update(['type' => LocationType::Stationary]);

    $location->refresh();
    expect($location->type)->toBe(LocationType::Stationary)
        ->and($location->traccar_server_id)->toBeNull()
        ->and($location->traccar_device_id)->toBeNull()
        ->and($location->traccar_device_name)->toBeNull()
        ->and($location->mobile_source)->toBeNull()
        ->and($location->mobile_update_interval_minutes)->toBeNull()
        ->and($location->mobile_position_updated_at)->toBeNull()
        ->and($location->hasFix())->toBeTrue(); // the coordinates are the subscriber's pin now
});

test('saving a mobile location keeps its Traccar link', function () {
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create([
        'traccar_device_id' => 7,
        'mobile_update_interval_minutes' => 15,
    ]);

    $location->update(['label' => 'Renamed']);

    $location->refresh();
    expect($location->traccar_server_id)->not->toBeNull()
        ->and($location->traccar_device_id)->toBe(7)
        ->and($location->mobile_update_interval_minutes)->toBe(15)
        ->and($location->mobile_position_updated_at)->not->toBeNull();
});

test('saving a location as stationary clears the stored fix time', function () {
    $location = Location::factory()->mobile()->withFix(39.0997, -94.5786)->create(['mobile_position_fixed_at' => now()->subHour()]);

    $location->update(['type' => LocationType::Stationary]);

    expect($location->refresh()->mobile_position_fixed_at)->toBeNull();
});

test('a mobile location\'s position time is its Traccar fix time, a stationary one\'s is its creation date', function () {
    $fixed = now()->subMinutes(7)->startOfSecond();
    $mobile = Location::factory()->mobile()->create(['mobile_position_fixed_at' => $fixed, 'mobile_position_updated_at' => now()]);
    $waiting = Location::factory()->mobile()->create(['mobile_position_fixed_at' => null, 'mobile_position_updated_at' => null]);
    $stationary = Location::factory()->create();

    expect($mobile->positionLocatedAt()->equalTo($fixed))->toBeTrue()
        ->and($waiting->positionLocatedAt())->toBeNull()
        ->and($stationary->positionLocatedAt()->equalTo($stationary->created_at))->toBeTrue();
});

test('the missing-device mark is cleared when the location\'s device changes, and when it becomes stationary', function () {
    $location = Location::factory()->mobile()->withFix(39.0, -94.0)->create(['traccar_device_id' => 33, 'traccar_device_missing_at' => now()->subDay()]);

    $location->update(['label' => 'Renamed']);
    expect($location->refresh()->traccar_device_missing_at)->not->toBeNull();

    $location->update(['traccar_device_id' => 38]);
    expect($location->refresh()->traccar_device_missing_at)->toBeNull();

    $location->forceFill(['traccar_device_missing_at' => now()])->saveQuietly();
    $location->update(['type' => LocationType::Stationary]);
    expect($location->refresh()->traccar_device_missing_at)->toBeNull();
});
