<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

/**
 * IngestedProductObserver (registered in AppServiceProvider) invokes the
 * real MatchWeatherAlertRules automatically on IngestedProduct::created --
 * so a plain IngestedProduct::factory()->create() below would silently
 * double-dispatch alongside every test's own explicit `(new
 * MatchWeatherAlertRules)($ingested)` call. withoutEvents() suppresses that
 * observer during fixture setup so each test controls exactly one
 * invocation, matching what it actually asserts on.
 */
function createIngestedProductWithoutMatching(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('a nationwide weather alerts rule matches any product referencing its product', function () {
    Queue::fake();
    // TO.W is part of the real catalog seeded by the products table migration
    // (see ExtractProductMetadataTest for the same convention); fetch it
    // instead of factory-creating it to avoid a unique-code collision.
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
    ]);
    $rule->products()->attach($product);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->channelId === $channel->id && $job->ingestedProductId === $ingested->id);
});

test('a weather alerts rule for a different product never matches', function () {
    Queue::fake();
    // Both TO.W and FF.W are part of the real seeded catalog; fetch them
    // instead of factory-creating to avoid a unique-code collision.
    $tornado = Product::where('code', 'TO.W')->sole();
    $flood = Product::where('code', 'FF.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($flood);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $tornado->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Wfo-mode rule matches on the issuing office, not the product', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Wfo-mode rule for a different office never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'BUF', 'location_id' => null]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a forecast discussion rule matches by wfo alone, with no product filter', function () {
    Queue::fake();
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => null, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('an inactive rule never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null, 'is_active' => false]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule never matches, since no product resolves that category yet', function () {
    // Documents the deliberate v1 scope decision (see plan header) rather
    // than leaving it an untested silent gap.
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a matched rule within its quiet hours window is suppressed and logged, not dispatched', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['timezone' => 'America/New_York']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id,
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->products()->attach($product);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    // Location mode also needs ugc_codes overlap — see a later task for the
    // real geometry path; here the rule matches trivially because both the
    // location's forecast_zone_ugc and the product's ugc_codes already agree.
    $location->forceFill(['forecast_zone_ugc' => 'NYZ072'])->save();
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->notification_channel_id)->toBe($channel->id);
});

test('a nationwide rule with quiet hours set always delivers, since it has no location to resolve a timezone from', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Location-mode rule matches when the location\'s resolved zone overlaps the product\'s ugc_codes', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Location-mode rule with no zone overlap never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ999']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a CountyZone-mode rule matches on direct UGC overlap with the stored codes', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'location_id' => null,
        'county_zone_codes' => 'NYZ072,NYC061',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYC061']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a CountyZone-mode rule with no overlap never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'location_id' => null,
        'county_zone_codes' => 'NYZ072',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NJZ103']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule matches when the location falls inside the product\'s parsed polygon', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 40.5, 'lng' => -74.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id,
        'polygon' => [
            ['lat' => 40.0, 'lon' => -74.5], ['lat' => 41.0, 'lon' => -74.5],
            ['lat' => 41.0, 'lon' => -73.5], ['lat' => 40.0, 'lon' => -73.5],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule does not match a location outside the product\'s parsed polygon', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 10.0, 'lng' => -10.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id,
        'polygon' => [
            ['lat' => 40.0, 'lon' => -74.5], ['lat' => 41.0, 'lon' => -74.5],
            ['lat' => 41.0, 'lon' => -73.5], ['lat' => 40.0, 'lon' => -73.5],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule falls back to Location-style zone matching when the product has no polygon', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'polygon' => null, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});
