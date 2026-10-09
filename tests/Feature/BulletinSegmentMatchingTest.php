<?php

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Pipeline\IngestProductText;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\IngestedProductSegment;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

/** A Weather Alerts rule watching the given catalog codes at the given locations, with one channel. */
function bsmRule(array|Location $locations, array $codes, string $mode = 'location', array $attributes = []): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => $mode, 'location_id' => null, ...$attributes]);
    $rule->locations()->attach(is_array($locations) ? $locations : [$locations]);
    $rule->products()->attach(Product::whereIn('code', $codes)->pluck('id')->all());
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

function bsmLocation(string $zone, array $attributes = []): Location
{
    return Location::factory()->create(['forecast_zone_ugc' => $zone, ...$attributes]);
}

/** A stored fixture bulletin with its segments built, made without the observer. */
function bsmBulletin(string $example, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'text' => file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt")),
        'format' => 'text', 'cccc' => 'kmob', 'vtec_office' => 'KMOB', 'received_at' => now(), ...$attributes,
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    return $bulletin;
}

/** A bulletin of hand-made segments: each entry overrides the segment factory's defaults. */
function bsmManualBulletin(array $segments, array $attributes = []): IngestedProduct
{
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create(['cccc' => 'kmob', 'vtec_office' => 'KMOB', ...$attributes]));

    foreach ($segments as $position => $segment) {
        IngestedProductSegment::factory()->for($bulletin)->create([...$segment, 'position' => $position]);
    }

    return $bulletin;
}

/** The ids of a bulletin's segments in the given phenomenon, in bulletin order. */
function bsmSegmentIds(IngestedProduct $bulletin, string $phenomenon): array
{
    return $bulletin->segments()->where('vtec_phenomenon', $phenomenon)->pluck('id')->all();
}

function bsmSquare(float $lat, float $lng, float $half = 0.1): array
{
    return [
        ['lat' => $lat - $half, 'lon' => $lng - $half], ['lat' => $lat + $half, 'lon' => $lng - $half],
        ['lat' => $lat + $half, 'lon' => $lng + $half], ['lat' => $lat - $half, 'lon' => $lng + $half],
    ];
}

test('Destin is matched by the Hurricane Watch section for its own zone, through the whole pipeline', function () {
    // Only the delivery is faked: the observer's ProcessIngestedProduct job must really run (sync queue).
    Queue::fake([DeliverWeatherAlert::class]);
    $destin = bsmLocation('FLZ206');
    $elsewhere = bsmLocation('FLZ299');
    $destinRule = bsmRule($destin, ['HU.A']);
    $elsewhereRule = bsmRule($elsewhere, ['HU.A']);

    $bulletin = app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/TCV_MOB.txt')),
        'test/tcvmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'wtus84', 'awipsid' => 'tcvmob', 'wmo_ddhhmm' => '071609', 'nwws_id' => '1'],
    );

    $destinWatch = $bulletin->segments()->where('vtec_phenomenon', 'HU')->get()->first(fn (IngestedProductSegment $s): bool => $s->ugc_codes === ['FLZ206']);
    expect($bulletin->ugc_codes)->toBe(['ALZ266']);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $destinRule->id
        && $job->locationId === $destin->id
        && $job->ingestedProductId === $bulletin->id
        && $job->segmentIds === [$destinWatch->id]);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $elsewhereRule->id);
});

test('a rule that watches only the second event of a section matches it though the bulletin\'s first VTEC line is another event', function () {
    Queue::fake();
    $location = bsmLocation('GMZ650');
    $rule = bsmRule($location, ['SC.Y']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $advisory = $bulletin->segments()->where('vtec_phenomenon', 'SC')->sole();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$advisory->id]);
});

test('a rule that watches both events of a section gets one delivery covering both', function () {
    Queue::fake();
    $location = bsmLocation('GMZ655');
    $rule = bsmRule($location, ['HU.A', 'SC.Y']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $firstSection = $bulletin->segments()->get()->take(2)->modelKeys();
    Queue::assertPushedTimes(DeliverWeatherAlert::class, 1);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === $firstSection);
});

test('a location in none of the bulletin\'s sections never matches', function () {
    Queue::fake();
    bsmRule(bsmLocation('GMZ999'), ['HU.A', 'SC.Y']);

    (new MatchWeatherAlertRules)(bsmBulletin('MWW_MOB'));

    Queue::assertNotPushed(DeliverWeatherAlert::class);
});

test('a nationwide rule matches every segment of the products it watches', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $watches = $bulletin->segments()->where('vtec_phenomenon', 'HU')->pluck('id')->all();
    expect($watches)->toHaveCount(2);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === $watches && $job->locationId === null);
});

test('a WFO rule matches on the issuing office and carries the segments of the products it watches', function () {
    Queue::fake();
    $rule = bsmRule([], ['SC.Y'], 'wfo', ['wfo' => 'MOB']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === bsmSegmentIds($bulletin, 'SC'));
});

test('a County/Zone rule matches the section whose zones it names', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'county_zone', ['county_zone_codes' => 'GMZ632']);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    $secondSection = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$secondSection->id]);
});

test('a Polygon rule matches the section whose own polygon holds the location', function () {
    Queue::fake();
    $location = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ040']);
    $rule = bsmRule($location, ['FF.W'], 'polygon');
    $bulletin = bsmManualBulletin([
        ['product_id' => Product::where('code', 'FF.W')->sole()->id, 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'ugc_codes' => ['NYZ069'], 'polygon' => bsmSquare(35.0, -90.0)],
        ['product_id' => Product::where('code', 'FF.W')->sole()->id, 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0043', 'ugc_codes' => ['NYZ070'], 'polygon' => bsmSquare(40.0, -105.0)],
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    $inside = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$inside->id]);
});

test('a bulletin whose first VTEC line is an event the catalog lacks is no longer skipped', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmManualBulletin([
        ['product_id' => null, 'vtec_phenomenon' => 'RP', 'vtec_significance' => 'S', 'vtec_event_number' => '0032'],
        ['product_id' => Product::where('code', 'HU.A')->sole()->id, 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A'],
    ], ['product_id' => null]);

    (new MatchWeatherAlertRules)($bulletin);

    $watch = $bulletin->segments()->get()->last();
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$watch->id]);
});

test('the matched location is the lowest-id location that matched any segment', function () {
    Queue::fake();
    $lower = bsmLocation('FLZ206');
    $higher = bsmLocation('FLZ299');
    $rule = bsmRule([$higher, $lower], ['HU.A']);
    $hurricaneWatch = Product::where('code', 'HU.A')->sole()->id;
    $bulletin = bsmManualBulletin([
        ['product_id' => $hurricaneWatch, 'ugc_codes' => ['FLZ299']],
        ['product_id' => $hurricaneWatch, 'ugc_codes' => ['FLZ206']],
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id
        && $job->locationId === $lower->id
        && $job->segmentIds === $bulletin->segments()->pluck('id')->all());
});

test('a CAP message with no text pair matches through its single segment', function () {
    Queue::fake();
    $location = bsmLocation('FLZ206');
    $rule = bsmRule($location, ['HU.A']);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc', 'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'vtec_action' => 'NEW', 'ugc_codes' => ['FLZ206'],
    ]));
    app(BuildBulletinSegments::class)($bulletin);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === [$bulletin->segments()->sole()->id]);
});

test('a bulletin with no segments is matched on its own columns, exactly as before', function () {
    Queue::fake();
    $location = bsmLocation('FLZ206');
    $rule = bsmRule($location, ['HU.A']);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id, 'ugc_codes' => ['FLZ206'],
    ]));

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->segmentIds === []);
});

test('a rule is never delivered twice for one bulletin', function () {
    Queue::fake();
    $rule = bsmRule([], ['HU.A'], 'nationwide');
    $bulletin = bsmBulletin('MWW_MOB');
    $channel = $rule->notificationChannels->first();
    DeliveryLog::factory()->create([
        'alert_rule_id' => $rule->id, 'notification_channel_id' => $channel->id, 'ingested_product_id' => $bulletin->id,
        'user_id' => $rule->user_id, 'channel_type' => $channel->channel_type,
    ]);

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    expect(DeliveryLog::count())->toBe(1);
});

test('a quiet-hours delivery is logged as suppressed with every matched event named and linked', function () {
    Queue::fake();
    $location = bsmLocation('GMZ655', ['timezone' => 'America/New_York']);
    $rule = bsmRule($location, ['HU.A', 'SC.Y'], 'location', [
        'name' => null,
        'quiet_hours_start' => now('America/New_York')->subHour()->format('H:i:s'),
        'quiet_hours_end' => now('America/New_York')->addHour()->format('H:i:s'),
    ]);
    $bulletin = bsmBulletin('MWW_MOB');

    (new MatchWeatherAlertRules)($bulletin);

    Queue::assertNotPushed(DeliverWeatherAlert::class);
    $log = DeliveryLog::sole();
    expect($log->status)->toBe('suppressed')
        ->and($log->alert_rule_id)->toBe($rule->id)
        ->and($log->subject)->toBe('Hurricane Watch + Small Craft Advisory')
        ->and($log->segments()->count())->toBe(2);
});
