<?php

use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;

test('a threshold rule is named in the owner\'s units, imperial exactly as before', function () {
    $imperial = AlertRule::factory()->create(['threshold_value' => 32]);
    $metric = AlertRule::factory()->for(User::factory()->create(['unit_system' => 'metric']))->create(['threshold_value' => 32]);
    $wind = AlertRule::factory()->for(User::factory()->create(['unit_system' => 'metric']))->create([
        'variable' => 'wind_speed', 'direction' => 'above', 'threshold_value' => 20,
    ]);

    expect($imperial->displayName())->toBe('Temperature Falls below 32.0°F')
        ->and($metric->displayName())->toBe('Temperature Falls below 0.0°C')
        ->and($wind->displayName())->toBe('Wind speed Rises above 32.2km/h');
});

test('a lightning rule is named in the owner\'s units', function () {
    $location = Location::factory()->create(['label' => 'Home']);
    $imperial = AlertRule::factory()->lightning()->for($location->user)->for($location)->create(['radius_miles' => 15]);
    $metric = AlertRule::factory()->lightning()->for(User::factory()->create(['unit_system' => 'metric']))->for($location)->create(['radius_miles' => 15]);

    expect($imperial->displayName())->toBe('Lightning within 15 mi of Home')
        ->and($metric->displayName())->toBe('Lightning within 24 km of Home');
});

test('an explicit unit system overrides the owner\'s, and a custom name is never converted', function () {
    $rule = AlertRule::factory()->create(['threshold_value' => 32]);

    expect($rule->displayName(UnitSystem::Metric))->toBe('Temperature Falls below 0.0°C')
        ->and(AlertRule::factory()->create(['name' => 'My 32°F alert'])->displayName(UnitSystem::Metric))->toBe('My 32°F alert');
});
