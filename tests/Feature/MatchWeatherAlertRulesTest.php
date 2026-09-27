<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
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

test('a forecast discussion rule matches the office\'s AFD, with no product picker needed', function () {
    Queue::fake();
    $afd = Product::where('code', 'AFD')->sole();
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'OKX', 'location_id' => null,
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    $ingested = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'KOKX']);

    (new MatchWeatherAlertRules)($ingested);

    Queue::assertPushed(DeliverWeatherAlert::class);
});

test('a forecast discussion rule never matches a different product type from the same office', function () {
    Queue::fake();
    $rule = AlertRule::factory()->create([
        'category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'BOU', 'location_id' => null,
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());
    // A TAF from the same office never resolves a product_id (not a catalog crosswalk entry an AlertRule can target).
    $taf = createIngestedProductWithoutMatching(['product_id' => null, 'awipsid' => 'tafbjc', 'cccc' => 'KBOU']);

    (new MatchWeatherAlertRules)($taf);

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

test('a Location-mode rule against a fixless mobile location never matches', function () {
    Queue::fake();
    $product = Product::where('code', 'TO.W')->sole();
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'location', 'location_id' => $location->id]);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
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
    $throwingRule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $badLocation->id]);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
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
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => $location->id]);
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

    $realRule = AlertRule::factory()->for($realUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $realRule->notificationChannels()->attach($realChannel);
    $testRule = AlertRule::factory()->for($testUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $testRule->notificationChannels()->attach($testChannel);

    $afd = Product::where('code', 'AFD')->sole();
    $ingestedProduct = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'ktwc', 'is_test' => true]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $realRule->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $testRule->id);
});

test('a real (non-test) product matches every active subscriber as before, regardless of is_test_account', function () {
    Queue::fake();
    $realUser = User::factory()->create(['is_test_account' => false]);
    $channel = NotificationChannel::factory()->for($realUser)->create();
    $rule = AlertRule::factory()->for($realUser)->create(['category' => 'forecast_discussion', 'matching_mode' => null, 'wfo' => 'TWC', 'location_id' => null]);
    $rule->notificationChannels()->attach($channel);

    $afd = Product::where('code', 'AFD')->sole();
    $ingestedProduct = createIngestedProductWithoutMatching(['product_id' => $afd->id, 'cccc' => 'ktwc', 'is_test' => false]);

    (new MatchWeatherAlertRules)($ingestedProduct);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job) => $job->ruleId === $rule->id);
});
