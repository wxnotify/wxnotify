<?php

use App\Actions\Weather\RenderAlertSubject;
use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

test('no custom template falls back to displayName()', function () {
    $rule = AlertRule::factory()->create(['category' => 'threshold', 'custom_subject_template' => null]);

    expect((new RenderAlertSubject)($rule))->toBe($rule->displayName());
});

test('a template using all three placeholders resolves correctly', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $product = Product::factory()->create(['name' => 'Tornado Warning']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => '{category}: {product} for {location}',
    ]);

    $result = (new RenderAlertSubject)($rule, $product);

    expect($result)->toBe('Weather Alerts: Tornado Warning for your area');
});

test('{product} falls back to the category label when no product is given', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {product}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: Threshold Alerts');
});

test('{location} resolves to the rule\'s location label when present', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $location = Location::factory()->create(['label' => 'Home']);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'threshold',
        'location_id' => $location->id,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near Home');
});

test('{location} falls back to the WFO code when the rule has a WFO but no location', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'forecast_discussion',
        'matching_mode' => null,
        'location_id' => null,
        'wfo' => 'OKX',
        'custom_subject_template' => 'Discussion for {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Discussion for WFO OKX');
});

test('{location} falls back to "your area" when the rule has neither a location nor a WFO', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'weather_alerts',
        'matching_mode' => 'nationwide',
        'location_id' => null,
        'custom_subject_template' => 'Near {location}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Near your area');
});

test('an unrecognized placeholder renders literally rather than erroring', function () {
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {bogus}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe('Alert: {bogus}');
});

test('a custom template is ignored for a Free-tier rule owner, falling back to displayName()', function () {
    $freeUser = User::factory()->create(['tier_override' => Tier::Free]);
    $rule = AlertRule::factory()->for($freeUser)->create([
        'category' => 'threshold',
        'custom_subject_template' => 'Alert: {product}',
    ]);

    expect((new RenderAlertSubject)($rule))->toBe($rule->displayName());
});
