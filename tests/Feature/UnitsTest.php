<?php

use App\Enums\UnitSystem;
use App\Models\User;
use App\Support\Units;

test('imperial is the identity, with the existing display shapes', function () {
    $units = new Units;

    expect($units->temperature(53.6))->toBe('54°F')
        ->and($units->temperature(35.0, 1))->toBe('35°F')
        ->and($units->speed(10.0))->toBe('10 mph')
        ->and($units->pressure(30.2712))->toBe('30.27 inHg')
        ->and($units->distance(10.0))->toBe('10 mi')
        ->and($units->distance(9.5))->toBe('9.5 mi')
        ->and($units->elevation(5085.0))->toBe('5,085 ft')
        ->and($units->depth(1.0))->toBe('1 in')
        ->and($units->temperatureValue(70.0))->toBe(70.0)
        ->and($units->minDistanceDisplay(10))->toBe(10)
        ->and($units->maxDistanceDisplay(50))->toBe(50)
        ->and($units->distanceUnitName())->toBe('miles');
});

test('metric converts each measurement with its own factor', function () {
    $units = new Units(UnitSystem::Metric);

    expect($units->temperature(53.6))->toBe('12°C')
        ->and($units->temperature(35.0, 1))->toBe('1.7°C')
        ->and($units->temperature(32.0, 1))->toBe('0°C')
        ->and($units->temperature(-40.0))->toBe('-40°C')
        ->and($units->speed(10.0))->toBe('16 km/h')
        ->and($units->pressure(30.2712))->toBe('1025 hPa')
        ->and($units->distance(10.0))->toBe('16.1 km')
        ->and($units->distance(15.0, 0))->toBe('24 km')
        ->and($units->elevation(5085.0))->toBe('1,550 m')
        ->and($units->depth(1.0))->toBe('2.5 cm')
        ->and($units->distanceUnitName())->toBe('km')
        ->and($units->minDistanceDisplay(10))->toBe(17)
        ->and($units->maxDistanceDisplay(50))->toBe(80);
});

test('metric never prints a negative zero', function () {
    expect((new Units(UnitSystem::Metric))->temperature(31.9))->toBe('0°C')
        ->and(Units::number(-0.04, 1))->toBe('0');
});

test('inverse conversions round-trip', function () {
    $units = new Units(UnitSystem::Metric);

    foreach ([-40.0, 0.0, 32.0, 35.0, 98.6, 105.0] as $fahrenheit) {
        expect($units->temperatureToFahrenheit($units->temperatureValue($fahrenheit)))->toEqualWithDelta($fahrenheit, 1e-9);
    }
    expect($units->speedToMph($units->speedValue(37.0)))->toEqualWithDelta(37.0, 1e-9)
        ->and($units->kilometersToMiles($units->distanceValue(15.0)))->toEqualWithDelta(15.0, 1e-9);
});

test('units resolve from a user, a rule owner and a queued report, defaulting to imperial', function () {
    $metric = User::factory()->create(['unit_system' => 'metric']);

    expect(Units::for($metric)->system)->toBe(UnitSystem::Metric)
        ->and(Units::for(null)->system)->toBe(UnitSystem::Imperial)
        ->and(Units::for(User::factory()->create())->system)->toBe(UnitSystem::Imperial)
        ->and(Units::fromReport(['units' => 'metric'])->system)->toBe(UnitSystem::Metric)
        ->and(Units::fromReport([])->system)->toBe(UnitSystem::Imperial)
        ->and(Units::fromReport(['units' => 'nonsense'])->system)->toBe(UnitSystem::Imperial);
});
