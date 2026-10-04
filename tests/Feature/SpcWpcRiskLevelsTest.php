<?php

use App\Support\SpcWpcRiskLevels;
use Database\Seeders\ProductCatalogSeeder;

beforeEach(function () {
    $this->seed(ProductCatalogSeeder::class);
});

test('only the Fire Weather and Convective Outlooks categories offer risk levels, from their products\' own options', function () {
    expect(SpcWpcRiskLevels::optionsByCategory())->toBe([
        'fire_weather' => ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms'],
        'convective_outlooks' => ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High'],
    ]);
});

test('split gives each category the stored levels it offers, in option order', function () {
    expect(SpcWpcRiskLevels::split(['High', 'Elevated', 'Marginal', 'Scattered Dry T-Storms']))->toBe([
        'fire_weather' => ['Elevated', 'Scattered Dry T-Storms'],
        'convective_outlooks' => ['Marginal', 'High'],
    ]);
});

test('split of nothing gives empty lists for every category', function () {
    expect(SpcWpcRiskLevels::split(null))->toBe(['fire_weather' => [], 'convective_outlooks' => []])
        ->and(SpcWpcRiskLevels::split([]))->toBe(['fire_weather' => [], 'convective_outlooks' => []]);
});

test('split drops a stored level no category offers', function () {
    expect(SpcWpcRiskLevels::split(['Nonsense', 'Slight']))->toBe(['fire_weather' => [], 'convective_outlooks' => ['Slight']]);
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
