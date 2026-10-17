<?php

use App\Enums\MatchingMode;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

test('a product with its own settings uses them, with its own locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $tornado = Product::factory()->create();
    $home = Location::factory()->create(['user_id' => $rule->user_id]);
    $cabin = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->products()->attach($tornado->id, ['matching_mode' => 'polygon', 'wfo' => null, 'county_zone_codes' => null]);
    DB::table('alert_rule_product_location')->insert([
        ['alert_rule_id' => $rule->id, 'product_id' => $tornado->id, 'location_id' => $cabin->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $tornado->id, 'location_id' => $home->id],
    ]);

    $settings = $rule->fresh()->settingsFor($tornado->id);

    expect($settings->mode)->toBe(MatchingMode::Polygon)
        ->and($settings->inherited)->toBeFalse()
        ->and($settings->locations->pluck('id')->all())->toBe([$home->id, $cabin->id]);
});

test('a product with no settings of its own inherits the rule\'s mode, WFO, zones and locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'county_zone_codes' => 'NYZ072,NYC061', 'location_id' => null]);
    $product = Product::factory()->create();
    $rule->products()->attach($product->id);
    $location = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->locations()->attach($location->id);

    $settings = $rule->fresh()->settingsFor($product->id);

    expect($settings->mode)->toBe(MatchingMode::CountyZone)
        ->and($settings->inherited)->toBeTrue()
        ->and($settings->countyZoneCodes)->toBe(['NYZ072', 'NYC061'])
        ->and($settings->locations->pluck('id')->all())->toBe([$location->id]);
});

test('settingsFor is null for a product the rule does not watch', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);

    expect($rule->settingsFor(Product::factory()->create()->id))->toBeNull();
});

test('two products of one rule keep separate locations', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    [$a, $b] = Product::factory()->count(2)->create();
    [$home, $cabin] = Location::factory()->count(2)->create(['user_id' => $rule->user_id]);
    $rule->products()->attach([$a->id => ['matching_mode' => 'location'], $b->id => ['matching_mode' => 'location']]);
    DB::table('alert_rule_product_location')->insert([
        ['alert_rule_id' => $rule->id, 'product_id' => $a->id, 'location_id' => $home->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $b->id, 'location_id' => $cabin->id],
    ]);

    $rule = $rule->fresh();

    expect($rule->settingsFor($a->id)->locations->pluck('id')->all())->toBe([$home->id])
        ->and($rule->settingsFor($b->id)->locations->pluck('id')->all())->toBe([$cabin->id]);
});

test('the migration backfills every existing pivot row from its rule', function () {
    // Replays the migration's SQL on rows shaped like pre-migration data.
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TBW', 'location_id' => null]);
    $product = Product::factory()->create();
    $location = Location::factory()->create(['user_id' => $rule->user_id]);
    $rule->products()->attach($product->id);
    $rule->locations()->attach($location->id);

    $migration = require base_path('database/migrations/2026_10_13_100001_add_matching_settings_to_alert_rule_product_table.php');
    $migration->backfill();

    $row = DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->first();
    expect($row->matching_mode)->toBe('wfo')
        ->and($row->wfo)->toBe('TBW')
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->pluck('location_id')->all())->toBe([$location->id]);
});

/**
 * What a rule's matcher would read for each product it watches: mode, office, zone codes and location ids.
 *
 * @return array<int, array{mode: ?string, wfo: ?string, codes: list<string>, locations: list<int>}>
 */
function productSettingsSnapshot(AlertRule $rule): array
{
    $rule = $rule->fresh();

    return $rule->products->sortBy('id')->mapWithKeys(function (Product $product) use ($rule): array {
        $settings = $rule->settingsFor($product->id);

        return [$product->id => [
            'mode' => $settings->mode?->value,
            'wfo' => $settings->wfo,
            'codes' => $settings->countyZoneCodes,
            'locations' => $settings->locations->pluck('id')->all(),
        ]];
    })->all();
}

/**
 * A rule shaped like pre-migration data: its own mode, office, codes and locations, products attached with no settings.
 */
function legacyProductRule(string $category, ?string $mode, array $attributes = [], int $locationCount = 0, int $productCount = 2): AlertRule
{
    $rule = AlertRule::factory()->create([
        'category' => $category,
        'matching_mode' => $mode,
        'location_id' => null,
        'risk_levels' => null,
        ...$attributes,
    ]);
    $rule->products()->attach(Product::factory()->count($productCount)->create()->pluck('id')->all());
    $rule->locations()->attach(Location::factory()->count($locationCount)->create(['user_id' => $rule->user_id])->pluck('id')->all());

    return $rule;
}

dataset('legacy product-based rules', [
    'nationwide' => ['weather_alerts', 'nationwide', [], 0],
    'location, several locations' => ['weather_alerts', 'location', [], 3],
    'wfo' => ['weather_alerts', 'wfo', ['wfo' => 'TBW'], 0],
    'county_zone' => ['weather_alerts', 'county_zone', ['county_zone_codes' => 'NYZ072,NYC061'], 0],
    'polygon' => ['weather_alerts', 'polygon', [], 1],
    'risk_area, SPC & WPC with risk levels' => ['spc_wpc', 'risk_area', ['risk_levels' => ['SLGT', 'MDT']], 2],
    'nationwide, SPC & WPC with risk levels' => ['spc_wpc', 'nationwide', ['risk_levels' => ['HIGH']], 0],
]);

test('the backfill leaves what each product of a legacy rule reads unchanged and copies the rule\'s values onto its rows', function (string $category, string $mode, array $attributes, int $locationCount) {
    $rule = legacyProductRule($category, $mode, $attributes, $locationCount);
    $before = productSettingsSnapshot($rule);

    loadAlertRuleProductMigration()->backfill();

    $rows = DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->get();
    expect(productSettingsSnapshot($rule))->toBe($before)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('matching_mode')->unique()->all())->toBe([$mode])
        ->and($rows->pluck('wfo')->unique()->all())->toBe([$attributes['wfo'] ?? null])
        ->and($rows->pluck('county_zone_codes')->unique()->all())->toBe([$attributes['county_zone_codes'] ?? null])
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->count())->toBe($locationCount * 2)
        ->and($rule->fresh()->risk_levels)->toBe($attributes['risk_levels'] ?? null);
})->with('legacy product-based rules');

test('the backfill keeps a legacy rule with no mode inheriting and gives it no per-product rows', function () {
    $rule = legacyProductRule('weather_alerts', null, [], 2);
    $before = productSettingsSnapshot($rule);

    loadAlertRuleProductMigration()->backfill();

    expect(productSettingsSnapshot($rule))->toBe($before)
        ->and(DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->whereNotNull('matching_mode')->count())->toBe(0)
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->count())->toBe(0);
});

test('the backfill leaves the pivot of a rule in another category alone', function (string $category) {
    $rule = legacyProductRule($category, 'location', [], 2, 1);

    loadAlertRuleProductMigration()->backfill();

    expect(DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->whereNotNull('matching_mode')->count())->toBe(0)
        ->and(DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->count())->toBe(0);
})->with(['threshold', 'daily_summary', 'lightning']);

function loadAlertRuleProductMigration(): object
{
    return require base_path('database/migrations/2026_10_13_100001_add_matching_settings_to_alert_rule_product_table.php');
}

test('rolling back copies a product-based rule\'s lowest-product settings and every product location onto the rule', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => null, 'wfo' => null, 'county_zone_codes' => null, 'location_id' => null]);
    [$first, $second] = Product::factory()->count(2)->create()->sortBy('id')->values();
    [$home, $cabin, $lake] = Location::factory()->count(3)->create(['user_id' => $rule->user_id]);
    $rule->products()->attach([
        $second->id => ['matching_mode' => 'location', 'wfo' => null, 'county_zone_codes' => null],
        $first->id => ['matching_mode' => 'county_zone', 'wfo' => null, 'county_zone_codes' => 'NYZ072,NYC061'],
    ]);
    DB::table('alert_rule_product_location')->insert([
        ['alert_rule_id' => $rule->id, 'product_id' => $second->id, 'location_id' => $home->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $second->id, 'location_id' => $cabin->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $first->id, 'location_id' => $cabin->id],
        ['alert_rule_id' => $rule->id, 'product_id' => $first->id, 'location_id' => $lake->id],
    ]);

    loadAlertRuleProductMigration()->restoreRuleColumns();

    $rule = $rule->fresh();
    expect($rule->matching_mode->value)->toBe('county_zone')
        ->and($rule->county_zone_codes)->toBe('NYZ072,NYC061')
        ->and($rule->wfo)->toBeNull()
        ->and($rule->locations->pluck('id')->all())->toBe([$home->id, $cabin->id, $lake->id]);
});

test('rolling back leaves a rule that still holds its own mode, an inherit-only rule and other categories alone', function () {
    $own = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TBW', 'location_id' => null]);
    $inheriting = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => null, 'location_id' => null]);
    $threshold = AlertRule::factory()->create(['category' => 'threshold', 'matching_mode' => null, 'location_id' => null]);
    $product = Product::factory()->create();
    $location = Location::factory()->create(['user_id' => $own->user_id]);
    $own->products()->attach($product->id, ['matching_mode' => 'nationwide']);
    $inheriting->products()->attach($product->id, ['matching_mode' => null]);
    $threshold->products()->attach($product->id, ['matching_mode' => 'nationwide']);
    DB::table('alert_rule_product_location')->insert(['alert_rule_id' => $own->id, 'product_id' => $product->id, 'location_id' => $location->id]);

    loadAlertRuleProductMigration()->restoreRuleColumns();

    expect($own->fresh()->matching_mode->value)->toBe('wfo')
        ->and($own->fresh()->wfo)->toBe('TBW')
        ->and($inheriting->fresh()->matching_mode)->toBeNull()
        ->and($threshold->fresh()->matching_mode)->toBeNull()
        ->and(DB::table('alert_rule_location')->count())->toBe(0);
});
