<?php

use App\Enums\ThresholdVariable;
use App\Enums\UnitSystem;

test('the unit label follows the system and defaults to imperial', function () {
    expect(ThresholdVariable::Temperature->unit())->toBe('°F')
        ->and(ThresholdVariable::Temperature->unit(UnitSystem::Metric))->toBe('°C')
        ->and(ThresholdVariable::WindSpeed->unit())->toBe('mph')
        ->and(ThresholdVariable::Gusts->unit(UnitSystem::Metric))->toBe('km/h')
        ->and(ThresholdVariable::Humidity->unit(UnitSystem::Metric))->toBe('%')
        ->and(ThresholdVariable::PrecipChance->unit(UnitSystem::Metric))->toBe('%');
});

test('display and canonical values convert, percentages never do', function () {
    expect(ThresholdVariable::Temperature->displayValue(32.0, UnitSystem::Metric))->toEqualWithDelta(0.0, 1e-9)
        ->and(ThresholdVariable::FeelsLike->displayValue(35.0, UnitSystem::Metric))->toEqualWithDelta(1.6667, 1e-3)
        ->and(ThresholdVariable::WindSpeed->displayValue(20.0, UnitSystem::Metric))->toEqualWithDelta(32.1869, 1e-3)
        ->and(ThresholdVariable::Humidity->displayValue(60.0, UnitSystem::Metric))->toBe(60.0)
        ->and(ThresholdVariable::Temperature->displayValue(32.0, UnitSystem::Imperial))->toBe(32.0)
        ->and(ThresholdVariable::Dewpoint->canonicalValue(0.0, UnitSystem::Metric))->toEqualWithDelta(32.0, 1e-9)
        ->and(ThresholdVariable::Gusts->canonicalValue(32.1869, UnitSystem::Metric))->toEqualWithDelta(20.0, 1e-3)
        ->and(ThresholdVariable::PrecipChance->canonicalValue(40.0, UnitSystem::Metric))->toBe(40.0);
});

test('format keeps the one-decimal shape a rule name has always used', function () {
    expect(ThresholdVariable::Temperature->format(32.0, UnitSystem::Imperial))->toBe('32.0°F')
        ->and(ThresholdVariable::Temperature->format(32.0, UnitSystem::Metric))->toBe('0.0°C')
        ->and(ThresholdVariable::Temperature->format(35.0, UnitSystem::Metric))->toBe('1.7°C')
        ->and(ThresholdVariable::WindSpeed->format(20.0, UnitSystem::Metric))->toBe('32.2km/h')
        ->and(ThresholdVariable::Humidity->format(60.0, UnitSystem::Metric))->toBe('60.0%');
});
