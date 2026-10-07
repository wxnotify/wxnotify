<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Enums\Tier;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

test('a WFO-mode rule with AFD attached matches that office\'s Area Forecast Discussion', function () {
    Queue::fake();
    $afd = Product::where('code', 'AFD')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->products()->attach($afd);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $rule->id);
});

test('a WFO-mode rule with AFD attached never matches an AFD from a different office', function () {
    Queue::fake();
    $afd = Product::where('code', 'AFD')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->products()->attach($afd);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'KBOU']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a WFO-mode rule with only AFD attached never matches a different product from the same office', function () {
    Queue::fake();
    $afd = Product::where('code', 'AFD')->sole();
    $tornado = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'BOU', 'location_id' => null,
    ]);
    $rule->products()->attach($afd);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $tornado->id, 'cccc' => 'KBOU']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
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

test('a nationwide spc_wpc rule with no risk_levels filter matches any product it references', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => null]);
    $rule->products()->attach($outlook);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Moderate']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->channelId === $channel->id);
});

test('an spc_wpc rule for a different product never matches', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($fire);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule filtering on a specific risk level matches a product stating that level', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => ['Moderate', 'High']]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Slight', 'Moderate']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule filtering on a specific risk level does not match a product stating only other levels', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => ['High']]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Marginal', 'Slight']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule filtering on a specific risk level does not match a no-risk-today product', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => ['High']]);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => []]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an spc_wpc rule filtering on a specific risk level still matches a product whose type has no risk-level extraction', function () {
    // e.g. the Fire Weather Outlook -- risk_levels is null (extraction
    // doesn't apply), not [] (attempted, found nothing), so a risk_levels
    // filter doesn't silently make the rule permanently unmatchable.
    Queue::fake();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => ['Critical']]);
    $rule->products()->attach($fire);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $fire->id, 'risk_levels' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a matched rule within its quiet hours window is suppressed and logged, not dispatched', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['timezone' => 'America/New_York']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->locations()->attach($location);
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
        ->and($log->notification_channel_id)->toBe($channel->id)
        ->and($log->subject)->toBe($rule->displayName())
        ->and($log->ingested_product_id)->toBe($ingested->id);
});

test('a rule that already has a DeliveryLog row for this exact product is never re-dispatched or re-logged (the PTSDY1-backfill rematch guard)', function () {
    Queue::fake();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $product = Product::where('code', 'TO.W')->sole();
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);
    DeliveryLog::factory()->create([
        'alert_rule_id' => $rule->id,
        'notification_channel_id' => $channel->id,
        'ingested_product_id' => $ingested->id,
        'user_id' => $rule->user_id,
        'channel_type' => $channel->channel_type,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::count())->toBe(1);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach($location);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach($location);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'polygon' => null, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule matches a location inside a HIGHER risk level than the one selected', function () {
    Queue::fake();
    // Real seeded catalog product, with risk_level_options already ordered
    // low-to-high: ["Marginal","Slight","Enhanced","Moderate","High"].
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 34.5, 'lng' => -95.5]); // inside Moderate below
    $rule = AlertRule::factory()->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area',
        'risk_levels' => ['Slight'],
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
            'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule does not match a location inside a LOWER risk level than the one selected', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 31, 'lng' => -99]); // inside Slight only, not Moderate
    $rule = AlertRule::factory()->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area',
        'risk_levels' => ['Moderate'],
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
            'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule with no risk_levels selected matches inside any risk level\'s area', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 31, 'lng' => -99]); // inside Slight only
    $rule = AlertRule::factory()->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area',
        'risk_levels' => null,
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
            'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule falls back to plain polygon matching when the product has no risk_level_polygons', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 40.5, 'lng' => -74.0]);
    $rule = AlertRule::factory()->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area',
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    // e.g. an SPC Mesoscale Discussion sharing this rule -- no risk levels at all.
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => null,
        'polygon' => [
            ['lat' => 40.0, 'lon' => -74.5], ['lat' => 41.0, 'lon' => -74.5],
            ['lat' => 41.0, 'lon' => -73.5], ['lat' => 40.0, 'lon' => -73.5],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Location-mode rule against a fixless mobile location never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ069']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule against a fixless mobile location falls back to Location matching and still never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id,
        'polygon' => [['lat' => 39.0, 'lon' => -95.0], ['lat' => 39.2, 'lon' => -95.0], ['lat' => 39.2, 'lon' => -94.8], ['lat' => 39.0, 'lon' => -94.8]],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an exception evaluating one rule\'s geography does not prevent a second, valid rule from matching', function () {
    // A malformed (empty) polygon on the product forces matchesPolygon()'s
    // ST_GeomFromText('POLYGON(())') to throw a real Postgres parse error --
    // confirmed directly against this database, not assumed. Without
    // per-rule isolation, this would abort the whole ->filter() pass and
    // silently suppress delivery to $validRule too.
    Queue::fake();
    Log::spy();
    $product = Product::where('code', 'TO.W')->sole();

    $badLocation = Location::factory()->create();
    $throwingRule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $throwingRule->locations()->attach($badLocation);
    $throwingRule->products()->attach($product);
    $throwingRule->notificationChannels()->attach(NotificationChannel::factory()->for($throwingRule->user)->create());

    $validRule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $validRule->products()->attach($product);
    $validChannel = NotificationChannel::factory()->for($validRule->user)->create();
    $validRule->notificationChannels()->attach($validChannel);

    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'polygon' => []]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn ($job) => $job->ruleId === $validRule->id && $job->channelId === $validChannel->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn ($job) => $job->ruleId === $throwingRule->id);
    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn ($message, $context) => $context['rule_id'] === $throwingRule->id && $context['ingested_product_id'] === $ingested->id);
});

test('a Polygon-mode rule matches against active_wwas\'s persisted polygon when the triggering row has none of its own', function () {
    // Simulates a continuation statement (e.g. SVS) that carries no LAT...LON
    // of its own, but the event's polygon is already known from an earlier
    // issuance -- see SyncActiveWwaTest for how that polygon gets there.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 40.5, 'lng' => -74.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement(
        'UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?',
        ['POLYGON((-74.5 40, -74.5 41, -73.5 41, -73.5 40, -74.5 40))', $wwa->id],
    );

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule does not match a location outside active_wwas\'s persisted polygon', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['lat' => 10.0, 'lng' => -10.0]);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement(
        'UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?',
        ['POLYGON((-74.5 40, -74.5 41, -73.5 41, -73.5 40, -74.5 40))', $wwa->id],
    );

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('when active_wwas has no persisted polygon for this VTEC key, matching falls back to the triggering row\'s own polygon field', function () {
    // vtec_office is set (so the lookup runs) but no active_wwas row exists
    // at all yet -- must not throw, must fall through cleanly.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $product->id, 'polygon' => null, 'ugc_codes' => ['NYZ072'],
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0099', 'vtec_year' => 2026,
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class); // falls back to Location-style zone matching, same as today
});

test('an is_test product only matches AlertRules belonging to a user flagged is_test_account, not real subscribers', function () {
    Queue::fake();
    $realUser = User::factory()->create(['is_test_account' => false]);
    $testUser = User::factory()->create(['is_test_account' => true]);
    $realChannel = NotificationChannel::factory()->for($realUser)->create();
    $testChannel = NotificationChannel::factory()->for($testUser)->create();

    $realRule = AlertRule::factory()->for($realUser)->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TWC', 'location_id' => null]);
    $realRule->notificationChannels()->attach($realChannel);
    $testRule = AlertRule::factory()->for($testUser)->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TWC', 'location_id' => null]);
    $testRule->notificationChannels()->attach($testChannel);

    $afd = Product::where('code', 'AFD')->sole();
    $realRule->products()->attach($afd);
    $testRule->products()->attach($afd);
    $ingestedProduct = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'ktwc', 'is_test' => true]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
});

test('a real (non-test) product matches every active subscriber as before, regardless of is_test_account', function () {
    Queue::fake();
    $realUser = User::factory()->create(['is_test_account' => false]);
    $channel = NotificationChannel::factory()->for($realUser)->create();
    $rule = AlertRule::factory()->for($realUser)->create(['category' => 'weather_alerts', 'matching_mode' => 'wfo', 'wfo' => 'TWC', 'location_id' => null]);
    $rule->notificationChannels()->attach($channel);

    $afd = Product::where('code', 'AFD')->sole();
    $rule->products()->attach($afd);
    $ingestedProduct = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'ktwc', 'is_test' => false]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $rule->id);
});

test('a Location-mode rule with two attached locations fires when only the second one overlaps the product\'s zone', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $noOverlap = Location::factory()->create(['forecast_zone_ugc' => 'NYZ999']);
    $overlaps = Location::factory()->create(['forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->locations()->attach([$noOverlap->id, $overlaps->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->locationId === $overlaps->id);
});

test('a Location-mode rule with zero attached locations never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location']);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('quiet hours are evaluated against the location that actually matched, not an unmatched sibling location in a different timezone', function () {
    // $awake is in a timezone where it's NOT currently quiet hours;
    // $asleep is in a timezone where it IS -- but $awake is the one
    // that overlaps the product's zone, so delivery must go through.
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $asleep = Location::factory()->create(['timezone' => 'America/New_York', 'forecast_zone_ugc' => 'NYZ999']);
    $awake = Location::factory()->create(['timezone' => 'Pacific/Kiritimati', 'forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->locations()->attach([$asleep->id, $awake->id]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a RiskArea-mode rule\'s quiet hours now suppress correctly -- previously silently never applied', function () {
    // Regression for the incidental bug fixed in this change: RiskArea was
    // missing from isWithinQuietHours()'s old matching-mode allow-list, so
    // a RiskArea rule's quiet hours never suppressed anything, even with a
    // real location and a real timezone.
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['label' => 'Moore OK', 'timezone' => 'America/New_York', 'lat' => 34.5, 'lng' => -95.5]);
    // A Plus-tier user and a custom template are required here so the
    // suppressed DeliveryLog's subject actually depends on $matchedLocation
    // -- displayName() (the default for spc_wpc, and what a Free-tier
    // user's custom template is ignored in favor of) renders product names,
    // not the location, and would prove nothing about location-threading
    // through this path.
    $plusUser = User::factory()->create(['tier_override' => Tier::Plus]);
    $rule = AlertRule::factory()->for($plusUser)->create([
        'category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'risk_levels' => ['Slight'],
        'custom_subject_template' => 'Alert for {location}',
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $channel = NotificationChannel::factory()->for($rule->user)->create();
    $rule->notificationChannels()->attach($channel);
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::sole()->status)->toBe('suppressed')
        ->and(DeliveryLog::sole()->subject)->toBe('Alert for Moore OK');
});

function quietHoursRuleForOwnedLocation(?string $profileTimezone, ?string $locationTimezone, string $quietAroundTimezone = 'America/New_York'): array
{
    $user = User::factory()->create(['timezone' => $profileTimezone]);
    $location = Location::factory()->for($user)->create(['timezone' => $locationTimezone, 'forecast_zone_ugc' => 'NYZ072']);
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'location',
        'quiet_hours_start' => now($quietAroundTimezone)->subHour()->format('H:i:s'),
        'quiet_hours_end' => now($quietAroundTimezone)->addHour()->format('H:i:s'),
    ]);
    $product = Product::where('code', 'TO.W')->sole();
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    return [$rule, createIngestedProductWithoutMatching(['product_id' => $product->id, 'ugc_codes' => ['NYZ072']])];
}

test('a matched location with no timezone is quiet-hours suppressed in the owner\'s profile timezone', function () {
    Queue::fake();
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: 'America/New_York', locationTimezone: null);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::sole()->status)->toBe('suppressed');
});

test('a matched location with no timezone is not suppressed when the owner has no profile timezone', function () {
    Queue::fake();
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: null, locationTimezone: null);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::count())->toBe(0);
});

test('a location\'s own timezone beats the profile timezone for quiet hours', function () {
    Queue::fake();
    // Quiet hours surround "now" in New York, so a profile timezone of New York would suppress;
    // the location's own Pacific/Kiritimati (a different hour of the day) must decide instead.
    [$rule, $ingested] = quietHoursRuleForOwnedLocation(profileTimezone: 'America/New_York', locationTimezone: 'Pacific/Kiritimati');

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a nationwide rule is never quiet-hours suppressed, even when the owner has a profile timezone', function () {
    Queue::fake();
    $user = User::factory()->create(['timezone' => 'America/New_York']);
    $product = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->for($user)->create([
        'category' => 'weather_alerts', 'matching_mode' => 'nationwide', 'location_id' => null,
        'quiet_hours_start' => '00:00:00', 'quiet_hours_end' => '23:59:59',
    ]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::where('status', 'suppressed')->count())->toBe(0);
});

function riskRule(array $productCodes, array $riskLevels): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null, 'risk_levels' => $riskLevels]);
    $rule->products()->attach(Product::whereIn('code', $productCodes)->pluck('id'));
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

test('a rule with only a Fire level ticked still matches a Day 1 outlook that states other levels', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['Elevated']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Marginal', 'Slight']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Convective level still filters the Day 1 outlook when a Fire level is ticked as well', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['Elevated', 'High']);

    (new MatchWeatherAlertRules)(createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Marginal', 'Slight']]));
    Queue::assertNotPushed(DeliverWeatherAlert::class);

    (new MatchWeatherAlertRules)(createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => ['Slight', 'High']]));
    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('Convective levels never block the Fire Weather Outlook', function () {
    Queue::fake();
    $fire = Product::where('code', 'SPC.FIRE')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['High']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $fire->id, 'risk_levels' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a Fire-only tick does not block a Day 1 outlook whose extraction found no categorical risk', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    riskRule(['SPC.OUTLOOK', 'SPC.FIRE'], ['Elevated']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $outlook->id, 'risk_levels' => []]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('levels that belong to none of the rule\'s product options apply no filter', function () {
    $this->seed(ProductCatalogSeeder::class);
    Queue::fake();
    $day2 = Product::where('code', 'DY2')->sole(); // has no risk_level_options
    riskRule(['DY2'], ['High']);
    $ingested = createIngestedProductWithoutMatching(['product_id' => $day2->id, 'risk_levels' => ['Marginal']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('the risk-area threshold ignores a ticked level from another category', function () {
    Queue::fake();
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $location = Location::factory()->create(['lat' => 31, 'lng' => -99]); // inside Slight only
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'risk_levels' => ['Elevated']]);
    $rule->locations()->attach($location);
    $rule->products()->attach($outlook);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching([
        'product_id' => $outlook->id,
        'risk_level_polygons' => [
            'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
        ],
    ]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

/**
 * A forecast zone (or county) row, so the matcher can tell which NWS office covers a location.
 * The location's cached UGC code is set separately on the Location.
 */
function coverZoneByOffice(string $table, array $row): void
{
    DB::table($table)->insert([
        'cwa' => 'BOU', 'lon' => -105.0, 'lat' => 40.0,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-105.1 39.9, -104.9 39.9, -104.9 40.1, -105.1 40.1, -105.1 39.9))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
        ...$row,
    ]);
}

function locationInBoulderZone(array $attributes = []): Location
{
    coverZoneByOffice('public_zones', ['state' => 'CO', 'zone' => '040', 'name' => 'Denver area', 'state_zone' => 'CO040']);

    return Location::factory()->create(['forecast_zone_ugc' => 'COZ040', 'county_ugc' => 'COC014', ...$attributes]);
}

function locationRuleWithAfd(Location $location, string $mode = 'location'): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null]);
    $rule->locations()->attach($location);
    $rule->products()->attach(Product::where('code', 'AFD')->sole());
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

test('a Location-mode rule with AFD attached matches the AFD issued by the office that covers the location', function () {
    Queue::fake();
    $location = locationInBoulderZone();
    $rule = locationRuleWithAfd($location);
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->locationId === $location->id);
});

test('a Location-mode rule with AFD attached never matches an AFD from a different office', function () {
    Queue::fake();
    locationRuleWithAfd(locationInBoulderZone());
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KOKX', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a Polygon-mode rule falls back to the issuing office for a product that has no polygon and no UGC codes', function () {
    Queue::fake();
    $rule = locationRuleWithAfd(locationInBoulderZone(), 'polygon');
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id);
});

test('the office comes from the county when the location has no forecast zone', function () {
    Queue::fake();
    coverZoneByOffice('counties', ['state' => 'CO', 'countyname' => 'Broomfield', 'fips' => '08014']);
    $rule = locationRuleWithAfd(Location::factory()->create(['forecast_zone_ugc' => null, 'county_ugc' => 'COC014']));
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id);
});

test('a location whose zone is unknown to the office table never matches an AFD', function () {
    Queue::fake();
    locationRuleWithAfd(Location::factory()->create(['forecast_zone_ugc' => 'COZ999', 'county_ugc' => 'COC999']));
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a mobile location with no position yet never matches an AFD by office', function () {
    Queue::fake();
    coverZoneByOffice('public_zones', ['state' => 'CO', 'zone' => '040', 'name' => 'Denver area', 'state_zone' => 'CO040']);
    locationRuleWithAfd(Location::factory()->mobile()->create(['forecast_zone_ugc' => 'COZ040']));
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a product that lists its own UGC codes is matched by them, never by office', function () {
    Queue::fake();
    locationRuleWithAfd(locationInBoulderZone());
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => ['NYZ999']]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a catalog product that normally carries UGC codes is not matched by office when a bulletin lacks them', function () {
    Queue::fake();
    $location = locationInBoulderZone();
    $tornado = Product::where('code', 'TO.W')->sole();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => null]);
    $rule->locations()->attach($location);
    $rule->products()->attach($tornado);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $tornado->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('an SPC and WPC rule never matches by office', function () {
    Queue::fake();
    $location = locationInBoulderZone();
    $product = Product::factory()->create(['family' => 'spc_wpc', 'has_ugc' => false, 'has_polygon' => false]);
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'polygon', 'location_id' => null]);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $product->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a County/Zone-mode rule with AFD attached still never matches, since it asks for explicit codes', function () {
    Queue::fake();
    locationInBoulderZone();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'county_zone', 'county_zone_codes' => 'COZ040', 'location_id' => null]);
    $rule->products()->attach(Product::where('code', 'AFD')->sole());
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => Product::where('code', 'AFD')->sole()->id, 'cccc' => 'KBOU', 'ugc_codes' => null]);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});
