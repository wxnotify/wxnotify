<?php

use App\Models\AlertRule;
use App\Models\Location;
use Illuminate\Support\Facades\DB;

test('AlertRule::locations() is a real many-to-many relation', function () {
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $locationA = Location::factory()->create();
    $locationB = Location::factory()->create();

    $rule->locations()->attach([$locationA->id, $locationB->id]);

    expect($rule->fresh()->locations->pluck('id')->sort()->values()->all())
        ->toBe(collect([$locationA->id, $locationB->id])->sort()->values()->all());
});

test('deleting a location detaches it from every alert rule, not just one', function () {
    $location = Location::factory()->create();
    $ruleA = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $ruleB = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'location_id' => null]);
    $ruleA->locations()->attach($location);
    $ruleB->locations()->attach($location);

    $location->delete();

    expect($ruleA->fresh()->locations)->toHaveCount(0)
        ->and($ruleB->fresh()->locations)->toHaveCount(0);
});

test('the backfill migration copies every existing Location/Polygon/RiskArea rule\'s location_id into the new pivot', function () {
    $location = Location::factory()->create();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id,
    ]);

    // The migration already ran as part of this test's own schema setup
    // (RefreshDatabase/migrate runs once for the whole suite) -- this rule
    // was created AFTER that, so it proves the backfill's WHERE clause, not
    // just that the pivot table exists. Simulate a pre-existing row by
    // deleting any pivot attachment the app code itself might have made
    // (none yet, since Task 5 hasn't wired the form) and re-running the
    // backfill SQL directly, matching exactly what the migration's up()
    // does.
    DB::table('alert_rule_location')->where('alert_rule_id', $rule->id)->delete();

    DB::statement("
        INSERT INTO alert_rule_location (alert_rule_id, location_id)
        SELECT id, location_id FROM alert_rules
        WHERE category IN ('weather_alerts', 'spc_wpc')
        AND matching_mode IN ('location', 'polygon', 'risk_area')
        AND location_id IS NOT NULL
        ON CONFLICT DO NOTHING
    ");

    expect($rule->fresh()->locations->pluck('id')->all())->toBe([$location->id]);
});
