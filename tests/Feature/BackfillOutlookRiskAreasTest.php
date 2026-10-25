<?php

use App\Actions\Pipeline\BackfillOutlookRiskAreas;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

/**
 * IngestedProductObserver fires ProcessIngestedProduct::dispatch() on every
 * IngestedProduct::create() -- which, on the sync queue connection tests
 * use, runs inline immediately (see ProcessIngestedProductTest's own note).
 * withoutEvents() keeps that out of these tests entirely, so each one
 * controls exactly one call into the action under test.
 */
function createOutlookRow(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('backfills risk_level_polygons onto the most recent un-enriched same-product row and re-matches it', function () {
    Queue::fake([DeliverWeatherAlert::class]);
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $swody1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'swody1', 'wmo_header_key' => 'acus01 kwns 070447 swody1', 'risk_level_polygons' => null, 'created_at' => now()->subMinutes(10)]);
    $ptsdy1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'ptsdy1', 'wmo_header_key' => 'wuus01 kwns 070447 ptsdy1', 'risk_levels' => ['Moderate'], 'risk_level_polygons' => [
        'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
    ]]);

    (new BackfillOutlookRiskAreas)($ptsdy1);

    expect($swody1->fresh()->risk_level_polygons)->toHaveKey('Moderate')
        ->and($swody1->fresh()->risk_levels)->toBe(['Moderate']);
    // Nationwide mode needs no geometry to match -- re-matching the
    // enriched SWODY1 row is what actually delivers this, not PTSDY1 itself.
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ingestedProductId === $swody1->id);
});

test('re-matching the backfilled row never double-delivers a rule that already matched before enrichment', function () {
    // A Nationwide-mode rule needs no geometry, so it would already have
    // matched (and logged a real DeliveryLog row) the moment SWODY1 itself
    // first arrived, before PTSDY1 ever shows up -- this proves the
    // DeliveryLog guard in MatchWeatherAlertRules::dispatchOrSuppress()
    // actually stops the rematch from sending it again.
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($outlook);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);

    $swody1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'swody1', 'wmo_header_key' => 'acus01 kwns 070447 swody1', 'risk_level_polygons' => null, 'created_at' => now()->subMinutes(10)]);
    DeliveryLog::factory()->create([
        'alert_rule_id' => $rule->id,
        'notification_channel_id' => $channel->id,
        'ingested_product_id' => $swody1->id,
        'user_id' => $rule->user_id,
        'channel_type' => $channel->channel_type,
    ]);
    $ptsdy1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'ptsdy1', 'wmo_header_key' => 'wuus01 kwns 070447 ptsdy1', 'risk_level_polygons' => [
        'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
    ]]);

    (new BackfillOutlookRiskAreas)($ptsdy1);

    expect(DeliveryLog::count())->toBe(1);
});

test('stands alone as its own matchable row when no recent un-enriched SWODY1 row exists', function () {
    Queue::fake([DeliverWeatherAlert::class]);
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $ptsdy1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'ptsdy1', 'wmo_header_key' => 'wuus01 kwns 070447 ptsdy1', 'risk_level_polygons' => [
        'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
    ]]);

    (new BackfillOutlookRiskAreas)($ptsdy1);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ingestedProductId === $ptsdy1->id);
});

test('a same-product row older than the 2-hour window is not backfilled', function () {
    Queue::fake([DeliverWeatherAlert::class]);
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $stale = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'swody1', 'wmo_header_key' => 'acus01 kwns 070447 swody1', 'risk_level_polygons' => null, 'created_at' => now()->subHours(3)]);
    $ptsdy1 = createOutlookRow(['product_id' => $outlook->id, 'awipsid' => 'ptsdy1', 'wmo_header_key' => 'wuus01 kwns 070447 ptsdy1', 'risk_level_polygons' => [
        'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
    ]]);

    (new BackfillOutlookRiskAreas)($ptsdy1);

    expect($stale->fresh()->risk_level_polygons)->toBeNull();
});
