<?php

use App\Actions\Alerts\GetActiveNowAtMyLocations;
use App\Actions\Weather\GetActiveAlertsForLocation;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Carbon::setTestNow('2026-10-07 16:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

const COVERAGE_READER_FAR_SQUARE = 'POLYGON((-91 34,-91 35,-90 35,-90 34,-91 34))';
const COVERAGE_READER_DESTIN_SQUARE = 'POLYGON((-86.6 30.2,-86.6 30.6,-86.3 30.6,-86.3 30.2,-86.6 30.2))';

function coverageReaderDestin(array $attributes = []): Location
{
    return Location::factory()->create([
        'label' => 'Destin', 'lat' => 30.39, 'lng' => -86.45, 'timezone' => 'America/Chicago',
        'forecast_zone_ugc' => 'FLZ206', 'county_ugc' => 'FLC091', ...$attributes,
    ]);
}

/** The Mobile Hurricane Watch as the old code left it: the event row names only the last zone it saw. */
function coverageReaderWatch(array $attributes = []): ActiveWwa
{
    return ActiveWwa::factory()->create([
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => 2026,
        'ugc_codes' => ['FLZ203'], 'expires' => null, ...$attributes,
    ]);
}

test('Destin is covered by the Hurricane Watch through its own zone row, though the event row names another zone', function () {
    $destin = coverageReaderDestin();
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => null]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    $events = app(GetActiveAlertsForLocation::class)->activeFor($destin);

    expect($events->modelKeys())->toBe([$watch->id])
        ->and($events->first()->eventName())->toBe('Hurricane Watch');
});

test('a zone that was cancelled no longer covers the location', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => null]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'status' => 'Cancelled', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('a zone whose end has passed no longer covers the location', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->subMinute()]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('a county row covers the location by its county code', function () {
    $watch = coverageReaderWatch();
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$watch->id]);
});

test('a zone row with a polygon is authoritative: inside covers, outside does not, though the zone is listed', function () {
    $outside = coverageReaderWatch();
    ActiveWwaArea::factory()->for($outside)->withPolygon(COVERAGE_READER_FAR_SQUARE)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();

    $outside->areas()->delete();
    ActiveWwaArea::factory()->for($outside)->withPolygon(COVERAGE_READER_DESTIN_SQUARE)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$outside->id]);
});

test('an event with no zone rows is read from its own zones and polygon, as before', function () {
    $legacy = coverageReaderWatch(['ugc_codes' => ['FLZ206']]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->modelKeys())->toBe([$legacy->id]);
});

test('an event ended as a whole is not listed even when its zone rows were left Active', function () {
    $watch = coverageReaderWatch(['status' => 'Cancelled']);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->isEmpty())->toBeTrue();
});

test('the event is listed once with the latest end of the rows that cover the location, ignoring rows that do not', function () {
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(2)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => now()->addHours(5)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ203', 'expires' => now()->addHours(9)]);

    $events = app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin());

    expect($events)->toHaveCount(1)
        ->and($events->first()->expires)->toEqual(now()->addHours(5));
});

test('an open-ended covering row makes the event open-ended at that location', function () {
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(2)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLC091', 'expires' => null]);

    expect(app(GetActiveAlertsForLocation::class)->activeFor(coverageReaderDestin())->first()->expires)->toBeNull();
});

test('the Active now list and the daily summary agree on the event and its end', function () {
    $destin = coverageReaderDestin();
    $watch = coverageReaderWatch(['expires' => now()->addHours(9)]);
    ActiveWwaArea::factory()->for($watch)->create(['ugc_code' => 'FLZ206', 'expires' => now()->addHours(4)]);

    $summary = app(GetActiveAlertsForLocation::class)($destin);
    $activeNow = (new GetActiveNowAtMyLocations)($destin->user)['events'];

    expect($summary)->toHaveCount(1)
        ->and($activeNow)->toHaveCount(1)
        ->and($summary[0]['event'])->toBe('Hurricane Watch')
        ->and($activeNow[0]['event'])->toBe('Hurricane Watch')
        ->and($summary[0]['expires'])->toBe($activeNow[0]['expires'])
        ->and($summary[0]['expires'])->toBe(now()->addHours(4)->setTimezone('America/Chicago')->toIso8601String());
});

/** A flash flood follow-up statement (no polygon of its own) for the event whose zone row carries a polygon around 40,-105. */
function coverageReaderStatementSetup(): array
{
    Queue::fake();
    $flashFlood = Product::where('code', 'FF.W')->sole();
    $key = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026];
    $event = ActiveWwa::factory()->create(['product_id' => $flashFlood->id, ...$key, 'ugc_codes' => ['COZ040']]);
    ActiveWwaArea::factory()->for($event)->withPolygon('POLYGON((-105.1 39.9,-105.1 40.1,-104.9 40.1,-104.9 39.9,-105.1 39.9))')->create(['ugc_code' => 'COZ040']);
    $statement = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        ...$key, 'product_id' => $flashFlood->id, 'ugc_codes' => ['COZ040'], 'polygon' => null, 'vtec_action' => 'CON', 'cccc' => 'kbou',
    ]));

    return [$flashFlood, $statement];
}

function coverageReaderPolygonRule(Location $location, Product $product): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon', 'location_id' => null]);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    return $rule;
}

test('a follow-up statement with no polygon is matched against the event\'s zone polygons', function () {
    [$flashFlood, $statement] = coverageReaderStatementSetup();
    $inside = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ099']);
    $outside = Location::factory()->create(['lat' => 41.5, 'lng' => -103.0, 'forecast_zone_ugc' => 'COZ040']);
    $insideRule = coverageReaderPolygonRule($inside, $flashFlood);
    $outsideRule = coverageReaderPolygonRule($outside, $flashFlood);

    (new MatchWeatherAlertRules)($statement);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $insideRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $outsideRule->id);
});

/** An event whose zone A (COZ040) is Active with a polygon around 40,-105 and zone B (COZ041) is Cancelled with a polygon around 41,-103. */
function coverageReaderSplitEventSetup(array $statement): array
{
    Queue::fake();
    $flashFlood = Product::where('code', 'FF.W')->sole();
    $key = ['vtec_office' => 'KBOU', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W', 'vtec_event_number' => '0043', 'vtec_year' => 2026];
    $event = ActiveWwa::factory()->create(['product_id' => $flashFlood->id, ...$key, 'ugc_codes' => ['COZ040']]);
    ActiveWwaArea::factory()->for($event)->withPolygon('POLYGON((-105.1 39.9,-105.1 40.1,-104.9 40.1,-104.9 39.9,-105.1 39.9))')->create(['ugc_code' => 'COZ040']);
    ActiveWwaArea::factory()->for($event)->withPolygon('POLYGON((-103.1 40.9,-103.1 41.1,-102.9 41.1,-102.9 40.9,-103.1 40.9))')->create(['ugc_code' => 'COZ041', 'status' => 'Cancelled']);
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        ...$key, 'product_id' => $flashFlood->id, 'vtec_action' => 'CON', 'cccc' => 'kbou', ...$statement,
    ]));

    return [$flashFlood, $bulletin];
}

test('a follow-up statement with no polygon is matched only against the polygons of the zones it names', function () {
    [$flashFlood, $statement] = coverageReaderSplitEventSetup(['ugc_codes' => ['COZ040'], 'polygon' => null]);
    $inCancelledZone = Location::factory()->create(['lat' => 41.0, 'lng' => -103.0, 'forecast_zone_ugc' => 'COZ099']);
    $inNamedZone = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ099']);
    $cancelledRule = coverageReaderPolygonRule($inCancelledZone, $flashFlood);
    $namedRule = coverageReaderPolygonRule($inNamedZone, $flashFlood);

    (new MatchWeatherAlertRules)($statement);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $namedRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $cancelledRule->id);
});

test('a follow-up statement that carries its own polygon is matched by it, not by the event\'s older zone polygons', function () {
    [$flashFlood, $statement] = coverageReaderSplitEventSetup([
        'ugc_codes' => ['COZ040'],
        'polygon' => [['lat' => 39.0, 'lon' => -108.1], ['lat' => 39.0, 'lon' => -107.9], ['lat' => 39.2, 'lon' => -107.9], ['lat' => 39.2, 'lon' => -108.1]],
    ]);
    $inOwnPolygon = Location::factory()->create(['lat' => 39.1, 'lng' => -108.0, 'forecast_zone_ugc' => 'COZ099']);
    $inOlderZonePolygon = Location::factory()->create(['lat' => 40.0, 'lng' => -105.0, 'forecast_zone_ugc' => 'COZ099']);
    $ownRule = coverageReaderPolygonRule($inOwnPolygon, $flashFlood);
    $olderRule = coverageReaderPolygonRule($inOlderZonePolygon, $flashFlood);

    (new MatchWeatherAlertRules)($statement);

    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $ownRule->id);
    Queue::assertNotPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $olderRule->id);
});
