<?php

use App\Actions\Pipeline\BackfillOutlookRiskAreas;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\PairOutlookAreasAfterDelay;
use App\Jobs\ProcessIngestedProduct;
use App\Mail\ChannelMessageMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SPC sends the Day 1 outlook as two bulletins about a second apart: SWODY1 (the text) and PTSDY1 (its
 * areal outline). These tests queue onto the real database driver and run the worker, so the jobs run
 * in the order production runs them, a delayed job waits in the jobs table until the clock reaches it,
 * and ShouldBeUnique's lock is taken at dispatch and released by the worker.
 */
const CHAN_PAIRING_RISK_POLYGONS = [
    'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
];

const CHAN_PAIRING_OTHER_RISK_POLYGONS = [
    'Slight' => [['lat' => 30, 'lon' => -100], ['lat' => 30, 'lon' => -90], ['lat' => 40, 'lon' => -90], ['lat' => 40, 'lon' => -100]],
];

/**
 * The WMO header's issuance time both bulletins of one outlook carry, whatever feed brought them.
 */
const CHAN_PAIRING_HEADER_TIME = '070447';

function chanPairingUseDatabaseQueue(): void
{
    config(['queue.default' => 'database']);
}

/**
 * The suite defaults to the sync queue, on which there is no pairing delay at all; every test here except
 * the sync one runs on the database queue, where the configured delay applies.
 */
beforeEach(function (): void {
    chanPairingUseDatabaseQueue();
});

/**
 * Runs every job that is due, oldest first, the way the production worker does (one try each). See
 * DeliverWeatherAlertUniqueTest for why the memory cap and timeout are lifted.
 */
function chanPairingRunDueJobs(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);
}

function chanPairingWaitOutDelay(): void
{
    test()->travel(BackfillOutlookRiskAreas::pairingDelaySeconds() + 1)->seconds();
}

/**
 * @return array{0: AlertRule, 1: NotificationChannel}
 */
function chanPairingOutlookRule(): array
{
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach(Product::where('code', 'SPC.OUTLOOK')->sole());
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();
    $rule->notificationChannels()->attach($channel);

    return [$rule, $channel];
}

/**
 * A risk-area rule for a place inside CHAN_PAIRING_RISK_POLYGONS' Moderate area: it matches only an outlook
 * that carries the risk areas.
 */
function chanPairingRiskAreaRule(): AlertRule
{
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'risk_area', 'risk_levels' => null]);
    $rule->locations()->attach(Location::factory()->create(['lat' => 34.5, 'lng' => -95.5]));
    $rule->products()->attach(Product::where('code', 'SPC.OUTLOOK')->sole());
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->email('nearby@example.com')->create());

    return $rule;
}

/**
 * Creates an SPC Day 1 outlook bulletin the way intake does, so the observer queues its ProcessIngestedProduct.
 * The row's wmo_ddhhmm column is the header time (as an EMWIN row stores it) unless overridden: an NWWS row
 * stores the feed's own, earlier issue time there, while its wmo_header_key still carries the header's.
 */
function chanPairingBulletin(string $awipsid, array $attributes = [], string $headerTime = CHAN_PAIRING_HEADER_TIME): IngestedProduct
{
    $isOutline = $awipsid === 'ptsdy1';
    $ttaaii = $isOutline ? 'wuus01' : 'acus01';

    return IngestedProduct::factory()->create([
        'product_id' => Product::where('code', 'SPC.OUTLOOK')->sole()->id,
        'awipsid' => $awipsid,
        'ttaaii' => $ttaaii,
        'cccc' => 'kwns',
        'wmo_ddhhmm' => $headerTime,
        'wmo_header_key' => "{$ttaaii} kwns {$headerTime} {$awipsid}",
        'source' => 'emwin',
        'risk_levels' => $isOutline ? ['Moderate'] : null,
        'risk_level_polygons' => $isOutline ? CHAN_PAIRING_RISK_POLYGONS : null,
        ...$attributes,
    ]);
}

function chanPairingQuietly(Closure $create): IngestedProduct
{
    return IngestedProduct::withoutEvents($create);
}

/**
 * @return list<string>
 */
function chanPairingQueuedJobNames(): array
{
    return DB::table('jobs')->orderBy('id')->pluck('payload')
        ->map(fn (string $payload): string => json_decode($payload, true)['displayName'])
        ->all();
}

function chanPairingLogsFor(IngestedProduct $product): int
{
    return DeliveryLog::where('ingested_product_id', $product->id)->count();
}

test('an areal outline that arrives a second before its outlook text is delivered once, as the outlook, with its risk areas, at once', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    [$rule, $channel] = chanPairingOutlookRule();
    $riskAreaRule = chanPairingRiskAreaRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');

    chanPairingRunDueJobs();

    expect(chanPairingLogsFor($ptsdy1))->toBe(0)
        ->and(DeliveryLog::where('alert_rule_id', $rule->id)->where('notification_channel_id', $channel->id)->sole()->ingested_product_id)->toBe($swody1->id)
        ->and(DeliveryLog::where('alert_rule_id', $riskAreaRule->id)->sole()->ingested_product_id)->toBe($swody1->id)
        ->and($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS)
        ->and($swody1->fresh()->risk_levels)->toBe(['Moderate'])
        ->and(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);

    chanPairingWaitOutDelay();
    chanPairingRunDueJobs();

    expect(DeliveryLog::count())->toBe(2)
        ->and(chanPairingLogsFor($swody1))->toBe(2)
        ->and(chanPairingQueuedJobNames())->toBe([]);
    Mail::assertSent(ChannelMessageMail::class, 2);
});

test('an outline and its outlook text that came through different feeds are paired by the WMO header time', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1', ['source' => 'emwin', 'wmo_ddhhmm' => '070447']);
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1', ['source' => 'nwws', 'wmo_ddhhmm' => '070445']);

    chanPairingRunDueJobs();
    chanPairingWaitOutDelay();
    chanPairingRunDueJobs();

    expect(DeliveryLog::sole()->ingested_product_id)->toBe($swody1->id)
        ->and($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS)
        ->and(chanPairingLogsFor($ptsdy1))->toBe(0);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('an areal outline whose outlook text never arrives waits out the delay, then is delivered once on its own', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');

    chanPairingRunDueJobs();
    $this->travel(BackfillOutlookRiskAreas::pairingDelaySeconds() - 1)->seconds();
    chanPairingRunDueJobs();

    expect(DeliveryLog::count())->toBe(0)
        ->and(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);
    Mail::assertNothingSent();

    $this->travel(2)->seconds();
    chanPairingRunDueJobs();
    chanPairingRunDueJobs();

    expect(chanPairingLogsFor($ptsdy1))->toBe(1)
        ->and(DeliveryLog::count())->toBe(1)
        ->and(chanPairingQueuedJobNames())->toBe([]);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('the pairing delay defaults to sixty seconds', function () {
    expect(BackfillOutlookRiskAreas::pairingDelaySeconds())->toBe(60);
});

test('on the sync queue there is no pairing delay: an outline stored first is matched on its own at once and its text never takes its risk areas', function () {
    Mail::fake();
    $this->freezeTime();
    config(['queue.default' => 'sync']);
    [$rule] = chanPairingOutlookRule();
    $riskAreaRule = chanPairingRiskAreaRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');

    expect(DeliveryLog::where('alert_rule_id', $riskAreaRule->id)->sole()->ingested_product_id)->toBe($ptsdy1->id)
        ->and($swody1->fresh()->risk_level_polygons)->toBeNull()
        ->and(DeliveryLog::where('alert_rule_id', $rule->id)->pluck('ingested_product_id')->sort()->values()->all())->toBe([$ptsdy1->id, $swody1->id])
        ->and(BackfillOutlookRiskAreas::pairingDelaySeconds())->toBe(0);
});

test('an outlook text takes a waiting outline\'s risk areas only while the outline is younger than the delay', function (int $secondsAfterOutline, bool $merged) {
    $this->freezeTime();
    chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $this->travel($secondsAfterOutline)->seconds();
    $swody1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));

    app(BackfillOutlookRiskAreas::class)->mergeWaitingOutline($swody1);

    expect($swody1->fresh()->risk_level_polygons)->toBe($merged ? CHAN_PAIRING_RISK_POLYGONS : null);
})->with([
    'one second inside the delay' => [59, true],
    'exactly at the delay' => [60, false],
]);

test('an outlook text that arrives first is paired with its areal outline at once, without waiting', function (int $secondsBetween) {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $swody1 = chanPairingBulletin('swody1');
    $this->travel($secondsBetween)->seconds();
    $ptsdy1 = chanPairingBulletin('ptsdy1');

    chanPairingRunDueJobs();

    expect(chanPairingQueuedJobNames())->toBe([])
        ->and(chanPairingLogsFor($swody1))->toBe(1)
        ->and(chanPairingLogsFor($ptsdy1))->toBe(0)
        ->and($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS);
    Mail::assertSent(ChannelMessageMail::class, 1);
})->with(['the same second' => 0, '32 seconds apart' => 32]);

test('a pairing delay of zero matches an unpaired areal outline on its own at once and never merges it into a later outlook text, as before', function () {
    Mail::fake();
    $this->freezeTime();
    config(['wxnotify.outlook_pairing_delay_seconds' => 0]);
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');

    chanPairingRunDueJobs();

    expect(chanPairingLogsFor($ptsdy1))->toBe(1)
        ->and(chanPairingLogsFor($swody1))->toBe(1)
        ->and($swody1->fresh()->risk_level_polygons)->toBeNull()
        ->and(chanPairingQueuedJobNames())->toBe([]);
});

test('a test areal outline, or one with no catalog product, is matched on its own at once, without waiting', function (array $attributes) {
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    $ptsdy1 = chanPairingBulletin('ptsdy1', $attributes);
    $matcher = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    chanPairingRunDueJobs();

    $matcher->shouldHaveReceived('__invoke')->once()->with(Mockery::on(fn (IngestedProduct $product): bool => $product->id === $ptsdy1->id));
    expect(chanPairingQueuedJobNames())->toBe([]);
})->with([
    'a test outline' => [['is_test' => true]],
    'no catalog product' => [['product_id' => null]],
]);

test('an outlook text of another issuance is never paired with the waiting areal outline', function (Closure $otherOutlook) {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $other = $otherOutlook();

    chanPairingRunDueJobs();
    chanPairingWaitOutDelay();
    chanPairingRunDueJobs();

    expect($other->fresh()->risk_level_polygons)->toBeNull()
        ->and(chanPairingLogsFor($ptsdy1))->toBe(1);
})->with([
    'a later issuance' => fn (): IngestedProduct => chanPairingBulletin('swody1', headerTime: '071230'),
    'the same issuance time more than two hours away' => fn (): IngestedProduct => chanPairingQuietly(
        fn (): IngestedProduct => chanPairingBulletin('swody1', ['created_at' => now()->subHours(3)]),
    ),
    'a generated test outlook text' => fn (): IngestedProduct => chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true])),
    'a replay copy of the outlook text' => fn (): IngestedProduct => chanPairingQuietly(
        fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true, 'replay_of_id' => chanPairingBulletin('swody1', ['created_at' => now()->subDays(5)], '010000')->id]),
    ),
    'an outlook text without a WMO header key' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['wmo_header_key' => null]),
    'an outlook text with a malformed WMO header key' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['wmo_header_key' => 'acus01 kwns']),
]);

test('a row stored before the outline that is not this issuance\'s real outlook text never takes its risk areas', function (Closure $candidate) {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    $other = chanPairingQuietly($candidate);
    $this->travel(1)->seconds();
    chanPairingBulletin('ptsdy1');

    chanPairingRunDueJobs();

    expect($other->fresh()->risk_level_polygons)->toBeNull()
        ->and(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);
})->with([
    'a generated test outlook text' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true]),
    'a replay copy of the outlook text' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true, 'replay_of_id' => chanPairingBulletin('swody1', ['created_at' => now()->subDays(5)], '010000')->id]),
    'an orphan outlook text of an earlier issuance' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['created_at' => now()->subMinutes(30)], '070415'),
    'an earlier outline of the same issuance without risk areas' => fn (): IngestedProduct => chanPairingBulletin('ptsdy1', ['risk_level_polygons' => null, 'risk_levels' => null]),
]);

test('an outlook text takes the risk areas of the newest same-issuance outline stored before it, within the delay', function () {
    $this->freezeTime();
    $older = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1', ['risk_level_polygons' => CHAN_PAIRING_OTHER_RISK_POLYGONS]));
    $this->travel(1)->seconds();
    chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $this->travel(1)->seconds();
    $swody1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));

    app(BackfillOutlookRiskAreas::class)->mergeWaitingOutline($swody1);

    expect($swody1->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS)
        ->and($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS)
        ->and($older->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_OTHER_RISK_POLYGONS);
});

test('an outlook text never takes the risk areas of an outline that is not its waiting one', function (Closure $scenario) {
    $this->freezeTime();
    $swody1 = $scenario();

    app(BackfillOutlookRiskAreas::class)->mergeWaitingOutline($swody1);

    expect($swody1->fresh()->risk_level_polygons)->toBeNull();
})->with([
    'an outline older than the delay' => function (): IngestedProduct {
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
        chanPairingWaitOutDelay();

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    },
    'an outline of another issuance' => function (): IngestedProduct {
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1', headerTime: '070415'));

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    },
    'a test outline' => function (): IngestedProduct {
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1', ['is_test' => true]));

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    },
    'a test outlook text' => function (): IngestedProduct {
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true]));
    },
    'a replay copy of the outlook text' => function (): IngestedProduct {
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
        $original = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1', ['created_at' => now()->subDays(5)], '010000'));

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true, 'replay_of_id' => $original->id]));
    },
    'a pairing delay of zero' => function (): IngestedProduct {
        config(['wxnotify.outlook_pairing_delay_seconds' => 0]);
        chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));

        return chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    },
]);

test('an outlook text takes a waiting outline\'s risk areas once, even when two runs see it without them', function () {
    $this->freezeTime();
    chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $this->travel(2)->seconds();
    $swody1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    $staleCopy = IngestedProduct::find($swody1->id);
    app(BackfillOutlookRiskAreas::class)->mergeWaitingOutline($swody1);
    chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1', ['risk_level_polygons' => CHAN_PAIRING_OTHER_RISK_POLYGONS, 'created_at' => now()->subSecond()]));

    app(BackfillOutlookRiskAreas::class)->mergeWaitingOutline($staleCopy);

    expect($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS);
});

test('an outline processed after its outlook text already took its risk areas schedules nothing and sends nothing more', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');
    chanPairingRunDueJobs();

    ProcessIngestedProduct::dispatch($ptsdy1->id);
    chanPairingRunDueJobs();

    expect(chanPairingQueuedJobNames())->toBe([])
        ->and(DeliveryLog::sole()->ingested_product_id)->toBe($swody1->id);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('a failed merge of a waiting outline never stops the outlook text being matched, and logs only its id and the exception class', function () {
    Log::spy();
    $swody1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    $backfill = Mockery::mock(BackfillOutlookRiskAreas::class);
    $backfill->shouldReceive('mergeWaitingOutline')->once()->andThrow(new RuntimeException('merge exploded for subscriber@example.com'));
    app()->instance(BackfillOutlookRiskAreas::class, $backfill);
    $matcher = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    (new ProcessIngestedProduct($swody1->id))->handle();

    $matcher->shouldHaveReceived('__invoke')->once();
    Log::shouldHaveReceived('error')->once()->with('ProcessIngestedProduct: failed to merge a waiting areal outline into an outlook', [
        'ingested_product_id' => $swody1->id,
        'exception' => RuntimeException::class,
    ]);
});

test('the delayed check is queued once per areal outline, however often its product is processed or the check is dispatched', function () {
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    ProcessIngestedProduct::dispatch($ptsdy1->id);

    chanPairingRunDueJobs();
    PairOutlookAreasAfterDelay::dispatch($ptsdy1->id);

    expect(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);
});

test('running the delayed check again after the pairing sends nothing more and never matches the outline on its own', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');
    chanPairingRunDueJobs();
    chanPairingWaitOutDelay();
    chanPairingRunDueJobs();

    PairOutlookAreasAfterDelay::dispatch($ptsdy1->id);
    chanPairingRunDueJobs();

    expect(DeliveryLog::sole()->ingested_product_id)->toBe($swody1->id);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('the delayed check does nothing for an areal outline that no longer exists', function () {
    Log::spy();
    $matcher = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    (new PairOutlookAreasAfterDelay(999999))->handle();

    $matcher->shouldNotHaveReceived('__invoke');
    Log::shouldHaveReceived('warning')->once()->with('PairOutlookAreasAfterDelay: areal outline no longer exists', ['ingested_product_id' => 999999]);
});

test('a pairing step that throws still matches the areal outline on its own and logs only ids and the exception class', function () {
    Log::spy();
    $this->freezeTime();
    $ptsdy1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $this->travel(1)->seconds();
    $swody1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('swody1'));
    $matched = [];
    $matcher = Mockery::mock(MatchWeatherAlertRules::class);
    $matcher->shouldReceive('__invoke')->andReturnUsing(function (IngestedProduct $product) use (&$matched, $swody1): void {
        $matched[] = $product->id;

        if ($product->id === $swody1->id) {
            throw new RuntimeException('matcher exploded for subscriber@example.com');
        }
    });
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    (new PairOutlookAreasAfterDelay($ptsdy1->id))->handle();

    expect($matched)->toBe([$swody1->id, $ptsdy1->id]);
    Log::shouldHaveReceived('error')->once()->with('PairOutlookAreasAfterDelay: pairing failed, matching the areal outline on its own', [
        'ingested_product_id' => $ptsdy1->id,
        'exception' => RuntimeException::class,
    ]);
});

test('a fallback that throws too is logged, never thrown into the queue', function () {
    Log::spy();
    $ptsdy1 = chanPairingQuietly(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $matcher = Mockery::mock(MatchWeatherAlertRules::class);
    $matcher->shouldReceive('__invoke')->andThrow(new RuntimeException('matcher exploded'));
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    (new PairOutlookAreasAfterDelay($ptsdy1->id))->handle();

    Log::shouldHaveReceived('error')->once()->with('PairOutlookAreasAfterDelay: matching the areal outline on its own failed', [
        'ingested_product_id' => $ptsdy1->id,
        'exception' => RuntimeException::class,
    ]);
});
