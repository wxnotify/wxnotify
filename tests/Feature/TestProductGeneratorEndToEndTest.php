<?php

use App\Actions\Pipeline\IngestProductText;
use App\Actions\Testing\GenerateTestProduct;
use App\Actions\Testing\RenderTestProductText;
use App\Enums\Tier;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\TestProductScenario;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * Creates a user with one channel and one matching rule, returning the rule.
 * Prefixed e2e* because Pest helper functions are global across the suite.
 *
 * @param  array<string, mixed>  $ruleAttributes
 */
function e2eCreateRuleFor(bool $isTestAccount, ?Product $product, array $ruleAttributes): AlertRule
{
    $user = User::factory()->create(['is_test_account' => $isTestAccount, 'tier_override' => Tier::Plus]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule = AlertRule::factory()->for($user)->create($ruleAttributes);

    if ($product !== null) {
        $rule->products()->attach($product);
    }
    $rule->notificationChannels()->attach($channel);

    return $rule;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function e2eWarningInput(array $overrides = []): array
{
    return [
        'mode' => 'new',
        'vtec_action' => 'NEW',
        'wfo' => 'PAH',
        'expires_minutes' => 30,
        'ugc_codes' => ['MOC023'],
        'hazard' => '60 mph wind gusts.',
        'hazard_source' => 'Radar indicated.',
        'impact' => 'Tree damage possible.',
        'call_to_action' => 'Move to an interior room.',
        'polygon_mode' => 'point_radius',
        'polygon_center_lat' => 36.83,
        'polygon_center_lon' => -89.12,
        'polygon_radius_miles' => 8,
        'damage_tags' => [],
        ...$overrides,
    ];
}

function e2eDispatchedRuleIds(): array
{
    return Queue::pushed(DeliverWeatherAlert::class)->map(fn (DeliverWeatherAlert $job) => $job->ruleId)->all();
}

test('a generated warning-family product matches the fixture test account and dispatches a real notification, never a real subscriber', function () {
    // Only DeliverWeatherAlert is faked (captured for the assertions below) --
    // ProcessIngestedProduct itself must still run for real, on the sync queue
    // connection tests use, or matching would never happen at all.
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'SV.W')->sole();
    $testRule = e2eCreateRuleFor(true, $product, ['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'PAH']);
    $realRule = e2eCreateRuleFor(false, $product, ['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'PAH']);

    $scenario = TestProductScenario::factory()->for($product)->create(['input' => e2eWarningInput()]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result->is_test)->toBeTrue();

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
});

test('a generated SPC mesoscale discussion parses its concatenated polygon and dispatches only to the fixture account', function () {
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'SPC.MD')->sole();
    $testRule = e2eCreateRuleFor(true, $product, ['category' => 'spc_wpc', 'matching_mode' => 'nationwide']);
    $realRule = e2eCreateRuleFor(false, $product, ['category' => 'spc_wpc', 'matching_mode' => 'nationwide']);

    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => [
            'mode' => 'new',
            'wfo' => 'WNS',
            'discussion_number' => '9001',
            'areas_affected' => 'CENTRAL/SOUTHERN AZ',
            'ugc_codes' => ['AZZ000'],
            'discussion_text' => 'Isolated storms are possible this evening.',
            'concerning' => 'SEVERE POTENTIAL...WATCH UNLIKELY',
            'polygon_mode' => 'point_radius',
            'polygon_center_lat' => 33.5,
            'polygon_center_lon' => -112.5,
            'polygon_radius_miles' => 20,
            'attn_wfos' => ['TWC', 'PSR'],
        ],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result->is_test)->toBeTrue()
        ->and($result->product_id)->toBe($product->id)
        ->and($result->polygon)->toHaveCount(4);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
});

test('a generated plain-text AFD dispatches to the fixture account forecast-discussion rule, never a real subscriber', function () {
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'AFD')->sole();
    $testRule = e2eCreateRuleFor(true, null, ['category' => 'forecast_discussion', 'wfo' => 'PIH']);
    $realRule = e2eCreateRuleFor(false, null, ['category' => 'forecast_discussion', 'wfo' => 'PIH']);

    $scenario = TestProductScenario::factory()->for($product)->create([
        'input' => ['mode' => 'new', 'wfo' => 'PIH', 'ugc_codes' => [], 'body_text' => 'Clear skies expected.'],
    ]);

    $result = app(GenerateTestProduct::class)($scenario);

    expect($result->product_id)->toBe($product->id);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
});

test('a continuing CAN scenario updates the same active_wwas row instead of creating a second one', function () {
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'SV.W')->sole();
    $first = TestProductScenario::factory()->for($product)->create(['input' => e2eWarningInput()]);
    $created = app(GenerateTestProduct::class)($first);

    $followUp = TestProductScenario::factory()->for($product)->create([
        'continues_scenario_id' => $first->id,
        'input' => e2eWarningInput([
            'mode' => 'continue',
            'vtec_action' => 'CAN',
            'vtec_event_number' => $created->vtec_event_number,
        ]),
    ]);
    app(GenerateTestProduct::class)($followUp);

    $rows = ActiveWwa::where('vtec_office', 'KPAH')->where('vtec_event_number', $created->vtec_event_number)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->is_test)->toBeTrue()
        ->and($rows->first()->status)->toBe('Cancelled');
});

test('a real, non-test ingestion still reaches real subscribers and is not restricted to the fixture account', function () {
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'SV.W')->sole();
    $testRule = e2eCreateRuleFor(true, $product, ['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'PAH']);
    $realRule = e2eCreateRuleFor(false, $product, ['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'PAH']);

    $text = (new RenderTestProductText)($product, e2eWarningInput(['vtec_event_number' => '0042']));

    $ingested = app(IngestProductText::class)($text, 'real/e2e-'.uniqid().'.txt', [
        'cccc' => 'kpah',
        'ttaaii' => 'wuus53',
        'awipsid' => 'svrpah',
        'wmo_ddhhmm' => now()->format('dHi'),
        'nwws_id' => 'REAL-'.uniqid(),
    ]);

    expect($ingested->is_test)->toBeFalsy();

    expect(e2eDispatchedRuleIds())->toContain($realRule->id, $testRule->id);
});

test('a synthetic product with no flagged fixture account dispatches nothing to anyone', function () {
    Queue::fake([DeliverWeatherAlert::class]);

    $product = Product::where('code', 'SV.W')->sole();
    e2eCreateRuleFor(false, $product, ['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'PAH']);

    $scenario = TestProductScenario::factory()->for($product)->create(['input' => e2eWarningInput()]);
    app(GenerateTestProduct::class)($scenario);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a real product sharing a synthetic event VTEC key does not resolve the synthetic active_wwas row', function () {
    $product = Product::where('code', 'SV.W')->sole();
    $scenario = TestProductScenario::factory()->for($product)->create(['input' => e2eWarningInput()]);
    $synthetic = app(GenerateTestProduct::class)($scenario);

    $real = $synthetic->replicate();
    $real->is_test = false;

    expect(ActiveWwa::query()->forProduct($synthetic)->count())->toBe(1)
        ->and(ActiveWwa::query()->forProduct($real)->count())->toBe(0);
});
