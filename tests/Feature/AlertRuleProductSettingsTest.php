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
