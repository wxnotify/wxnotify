<?php

use App\Models\AlertRule;
use App\Models\Product;
use App\Support\SpcWpcRiskLevels;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

test('only the Flooding, Fire Weather and Convective Outlooks categories offer risk levels, from their products\' own options', function () {
    expect(SpcWpcRiskLevels::optionsByCategory())->toBe([
        'flooding_and_hydrological' => ['Marginal', 'Slight', 'Moderate', 'High'],
        'fire_weather' => ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms'],
        'convective_outlooks' => ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High'],
    ]);
});

test('each category\'s ticks are the levels its own watched products hold, in option order', function () {
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach([
        Product::where('code', 'SPC.OUTLOOK')->value('id') => ['matching_mode' => 'nationwide', 'risk_levels' => json_encode(['High', 'Moderate'])],
        Product::where('code', 'WPC.ERO')->value('id') => ['matching_mode' => 'nationwide', 'risk_levels' => json_encode(['Slight'])],
    ]);

    expect(SpcWpcRiskLevels::forForm($rule->fresh()))->toBe([
        'flooding_and_hydrological' => ['Slight'],
        'fire_weather' => [],
        'convective_outlooks' => ['Moderate', 'High'],
    ]);
});

test('a rule saved before per-product levels shows its flat levels only under the categories of the products it watches', function () {
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => ['High', 'Elevated', 'Nonsense']]);
    $rule->products()->attach(Product::whereIn('code', ['SPC.OUTLOOK', 'SPC.FIRE'])->pluck('id')->all());

    expect(SpcWpcRiskLevels::forForm($rule->fresh()))->toBe([
        'flooding_and_hydrological' => [],
        'fire_weather' => ['Elevated'],
        'convective_outlooks' => ['High'],
    ]);
});

test('flatten merges the per-category lists into one unique list', function () {
    expect(SpcWpcRiskLevels::flatten(['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Slight', 'High']]))->toBe(['Elevated', 'Slight', 'High'])
        ->and(SpcWpcRiskLevels::flatten(['fire_weather' => ['Elevated'], 'convective_outlooks' => ['Elevated']]))->toBe(['Elevated']);
});

test('flatten tolerates missing, null and junk state', function () {
    expect(SpcWpcRiskLevels::flatten(null))->toBe([])
        ->and(SpcWpcRiskLevels::flatten([]))->toBe([])
        ->and(SpcWpcRiskLevels::flatten(['fire_weather' => null, 'convective_outlooks' => [true, 5, 'Slight']]))->toBe(['Slight']);
});
