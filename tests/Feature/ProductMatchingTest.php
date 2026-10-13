<?php

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use App\Support\ProductMatching;
use Illuminate\Support\Facades\DB;

dataset('product matching cases', [
    'weather alert with a polygon' => [
        AlertCategory::WeatherAlerts,
        ['has_polygon' => true],
        MatchingMode::Polygon,
        [MatchingMode::Nationwide, MatchingMode::Location, MatchingMode::Wfo, MatchingMode::CountyZone, MatchingMode::Polygon],
        [MatchingMode::Nationwide, MatchingMode::Location, MatchingMode::Wfo, MatchingMode::CountyZone, MatchingMode::Polygon],
    ],
    'plain weather alert' => [
        AlertCategory::WeatherAlerts,
        ['has_polygon' => false],
        MatchingMode::Location,
        [MatchingMode::Nationwide, MatchingMode::Location, MatchingMode::Wfo, MatchingMode::CountyZone, MatchingMode::Polygon],
        [MatchingMode::Nationwide, MatchingMode::Location, MatchingMode::Wfo, MatchingMode::CountyZone, MatchingMode::Polygon],
    ],
    'SPC product with risk levels' => [
        AlertCategory::SpcWpc,
        ['risk_level_options' => ['MRGL', 'SLGT', 'ENH']],
        MatchingMode::RiskArea,
        [MatchingMode::Nationwide, MatchingMode::RiskArea],
        [MatchingMode::Nationwide, MatchingMode::Polygon, MatchingMode::RiskArea],
    ],
    'SPC product with an empty risk level list' => [
        AlertCategory::SpcWpc,
        ['risk_level_options' => []],
        MatchingMode::Polygon,
        [MatchingMode::Nationwide, MatchingMode::Polygon],
        [MatchingMode::Nationwide, MatchingMode::Polygon, MatchingMode::RiskArea],
    ],
    'plain SPC product' => [
        AlertCategory::SpcWpc,
        ['risk_level_options' => null],
        MatchingMode::Polygon,
        [MatchingMode::Nationwide, MatchingMode::Polygon],
        [MatchingMode::Nationwide, MatchingMode::Polygon, MatchingMode::RiskArea],
    ],
]);

it('defaults, offers and accepts the right modes for each kind of product', function (
    AlertCategory $category,
    array $attributes,
    MatchingMode $default,
    array $offered,
    array $accepted,
) {
    $product = Product::factory()->create($attributes);

    expect(ProductMatching::defaultMode($category, $product))->toBe($default)
        ->and(ProductMatching::offeredModes($category, $product))->toBe($offered)
        ->and(ProductMatching::acceptedModes($category))->toBe($accepted);
})->with('product matching cases');

it('always offers the default mode and accepts everything it offers', function (
    AlertCategory $category,
    array $attributes,
) {
    $product = Product::factory()->create($attributes);

    expect(ProductMatching::offeredModes($category, $product))->toContain(ProductMatching::defaultMode($category, $product))
        ->and(ProductMatching::acceptedModes($category))->toContain(...ProductMatching::offeredModes($category, $product));
})->with('product matching cases');

it('rejects categories that have no per-product matching', function (AlertCategory $category) {
    $product = Product::factory()->create();

    expect(fn () => ProductMatching::offeredModes($category, $product))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProductMatching::acceptedModes($category))->toThrow(InvalidArgumentException::class)
        ->and(fn () => ProductMatching::defaultMode($category, $product))->toThrow(InvalidArgumentException::class);
})->with([AlertCategory::DailySummary, AlertCategory::Threshold, AlertCategory::Lightning]);

it('preselects the lowest-id location, not the first one created', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['id' => 9000]);
    Location::factory()->for($user)->create(['id' => 5]);
    Location::factory()->for($user)->create(['id' => 70]);
    Location::factory()->create(['id' => 1]); // someone else's

    $product = Product::factory()->create();
    $row = ProductMatching::defaultRow($user, AlertCategory::WeatherAlerts, $product);

    expect($row)->toBe([
        'product_id' => $product->id,
        'enabled' => false,
        'matching_mode' => 'location',
        'location_ids' => [5],
        'wfo' => null,
        'county_zone_codes' => [],
    ]);
});

it('returns no locations for a user who has none', function () {
    $user = User::factory()->create();
    $product = Product::factory()->create(['has_polygon' => true]);

    $row = ProductMatching::defaultRow($user, AlertCategory::WeatherAlerts, $product);

    expect($row['matching_mode'])->toBe('polygon')
        ->and($row['location_ids'])->toBe([])
        ->and($row['enabled'])->toBeFalse();
});

it('carries the location for every product whose default mode is location-based', function (
    AlertCategory $category,
    array $attributes,
    MatchingMode $default,
) {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create();
    $product = Product::factory()->create($attributes);

    $row = ProductMatching::defaultRow($user, $category, $product);

    expect($row['matching_mode'])->toBe($default->value)
        ->and($row['location_ids'])->toBe([$location->id]);
})->with('product matching cases');

it('uses a first location id passed in without querying locations', function () {
    $user = User::factory()->create();
    Location::factory()->for($user)->create(['id' => 3]);
    $product = Product::factory()->create();

    DB::enableQueryLog();
    $row = ProductMatching::defaultRow($user, AlertCategory::WeatherAlerts, $product, 42);
    $queries = collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'locations'));
    DB::disableQueryLog();

    expect($row['location_ids'])->toBe([42])
        ->and($queries)->toBeEmpty();
});
