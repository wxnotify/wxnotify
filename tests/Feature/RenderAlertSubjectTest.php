<?php

use App\Actions\Weather\RenderAlertSubject;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;

test('no custom template falls back to displayName()', function () {
    $rule = AlertRule::factory()->create(['category' => 'threshold', 'custom_subject_template' => null]);

    expect((new RenderAlertSubject)($rule))->toBe($rule->displayName());
});

test('a template using all three placeholders resolves correctly', function () {
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => '{category}: {product} for {location}',
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('Weather Alerts: Tornado Warning for your area');
});

test('{product} falls back to the category label when no product is given', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {product}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: Threshold Alerts');
});

test('{location} resolves to the rule\'s location label when present', function () {
    $location = Location::factory()->create(['label' => 'Home']);
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'location_id' => $location->id,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near Home');
});

test('{location} falls back to the WFO code when the rule has a WFO but no location', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion',
        'matching_mode' => null,
        'location_id' => null,
        'wfo' => 'OKX',
        'custom_subject_template' => 'Discussion for {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Discussion for WFO OKX');
});

test('{location} falls back to "your area" when the rule has neither a location nor a WFO', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near your area');
});

test('an unrecognized placeholder renders literally rather than erroring', function () {
    $rule = AlertRule::factory()->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {bogus}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: {bogus}');
});
