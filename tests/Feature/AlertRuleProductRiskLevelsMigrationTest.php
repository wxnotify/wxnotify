<?php

use App\Models\AlertRule;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

function loadAlertRuleProductRiskLevelsMigration(): object
{
    return require base_path('database/migrations/2026_10_19_030951_add_risk_levels_to_alert_rule_product_table.php');
}

/**
 * A rule shaped like data from before per-product risk levels: one flat list on the rule, products attached with none.
 *
 * @param  list<string>  $codes
 */
function legacyRiskRule(array $codes, ?array $riskLevels, string $category = 'spc_wpc'): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => $category, 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => $riskLevels]);
    $rule->products()->attach(Product::whereIn('code', $codes)->pluck('id')->all(), ['matching_mode' => 'nationwide']);

    return $rule;
}

/**
 * @return array<string, mixed> each product's stored pivot risk_levels (decoded), by product code
 */
function pivotRiskLevels(AlertRule $rule): array
{
    return DB::table('alert_rule_product')->join('products', 'products.id', '=', 'alert_rule_product.product_id')
        ->where('alert_rule_id', $rule->id)->orderBy('products.code')
        ->pluck('alert_rule_product.risk_levels', 'products.code')
        ->map(fn (?string $levels): mixed => $levels === null ? null : json_decode($levels, true))
        ->all();
}

/**
 * @return array<string, list<string>> what matching reads for each product, by product code
 */
function effectiveRiskLevels(AlertRule $rule): array
{
    $rule = $rule->fresh();

    return $rule->products->sortBy('code')->mapWithKeys(fn (Product $product): array => [$product->code => $rule->riskLevelsFor($product->id)])->all();
}

test('the backfill gives each risk-level product the rule\'s levels it offers, so matching reads the same levels as before', function () {
    $rule = legacyRiskRule(['SPC.OUTLOOK', 'WPC.ERO', 'SPC.FIRE', 'SPC.MD'], ['Moderate', 'Elevated', 'Enhanced']);
    $before = effectiveRiskLevels($rule);

    loadAlertRuleProductRiskLevelsMigration()->backfill();

    expect(pivotRiskLevels($rule))->toBe([
        'SPC.FIRE' => ['Elevated'],
        'SPC.MD' => null,
        'SPC.OUTLOOK' => ['Enhanced', 'Moderate'],
        'WPC.ERO' => ['Moderate'],
    ])
        ->and(effectiveRiskLevels($rule))->toBe($before)
        ->and($rule->fresh()->risk_levels)->toBe(['Moderate', 'Elevated', 'Enhanced']);
});

test('the backfill gives a rule with no levels an empty list, meaning every level', function (?array $riskLevels) {
    $rule = legacyRiskRule(['SPC.OUTLOOK'], $riskLevels);

    loadAlertRuleProductRiskLevelsMigration()->backfill();

    expect(pivotRiskLevels($rule))->toBe(['SPC.OUTLOOK' => []]);
})->with([[null], [[]]]);

test('the backfill is idempotent and never overwrites levels a product already holds', function () {
    $rule = legacyRiskRule(['SPC.OUTLOOK', 'WPC.ERO'], ['Moderate']);
    DB::table('alert_rule_product')->where('alert_rule_id', $rule->id)->where('product_id', Product::where('code', 'WPC.ERO')->value('id'))
        ->update(['risk_levels' => json_encode(['High'])]);

    loadAlertRuleProductRiskLevelsMigration()->backfill();
    loadAlertRuleProductRiskLevelsMigration()->backfill();

    expect(pivotRiskLevels($rule))->toBe(['SPC.OUTLOOK' => ['Moderate'], 'WPC.ERO' => ['High']]);
});

test('the backfill leaves a Weather Alerts rule alone', function () {
    $rule = legacyRiskRule(['TO.W'], ['Moderate'], 'weather_alerts');

    loadAlertRuleProductRiskLevelsMigration()->backfill();

    expect(pivotRiskLevels($rule))->toBe(['TO.W' => null]);
});
