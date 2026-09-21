<?php

use App\Settings\GeneralSettings;
use App\Settings\TierThresholdSettings;
use Illuminate\Support\Facades\Cache;

test('general settings default to maintenance off with no custom message', function () {
    $settings = app(GeneralSettings::class);

    expect($settings->maintenance_mode)->toBeFalse()
        ->and($settings->maintenance_message)->toBeNull();
});

test('tier and threshold settings default to the limits the app shipped with', function () {
    $settings = app(TierThresholdSettings::class);

    expect($settings->free_locations)->toBe(1)
        ->and($settings->plus_locations)->toBe(5)
        ->and($settings->free_no_cost_channels)->toBe(1)
        ->and($settings->free_traccar_servers)->toBe(0)
        ->and($settings->plus_traccar_servers)->toBe(1)
        ->and($settings->pro_traccar_servers)->toBe(3)
        ->and($settings->free_traccar_devices_per_server)->toBe(0)
        ->and($settings->plus_traccar_devices_per_server)->toBe(5)
        ->and($settings->pro_traccar_devices_per_server)->toBe(10)
        ->and($settings->lightning_min_radius_miles)->toBe(10);
});

test('a saved setting is read back', function () {
    $settings = app(TierThresholdSettings::class);
    $settings->plus_locations = 8;
    $settings->save();

    expect(app(TierThresholdSettings::class)->refresh()->plus_locations)->toBe(8);
});

test('settings values are cached', function () {
    expect(config('settings.cache.enabled'))->toBeTrue();

    Cache::flush();
    app()->forgetInstance(TierThresholdSettings::class);
    app(TierThresholdSettings::class)->plus_locations;

    expect(Cache::has('settings.'.TierThresholdSettings::cacheKey()))->toBeTrue();
});
