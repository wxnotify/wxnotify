<?php

use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\PairOutlookAreasAfterDelay;
use App\Jobs\ProcessIngestedProduct;
use App\Mail\ChannelMessageMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
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

const CHAN_PAIRING_ISSUANCE = '070445';

function chanPairingUseDatabaseQueue(): void
{
    config(['queue.default' => 'database']);
}

/**
 * Runs every job that is due, oldest first, the way the production worker does (one try each). See
 * DeliverWeatherAlertUniqueTest for why the memory cap and timeout are lifted.
 */
function chanPairingRunDueJobs(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);
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
 * Creates an SPC Day 1 outlook bulletin the way intake does, so the observer queues its ProcessIngestedProduct.
 */
function chanPairingBulletin(string $awipsid, array $attributes = []): IngestedProduct
{
    $isOutline = $awipsid === 'ptsdy1';

    return IngestedProduct::factory()->create([
        'product_id' => Product::where('code', 'SPC.OUTLOOK')->sole()->id,
        'awipsid' => $awipsid,
        'cccc' => 'kwns',
        'wmo_ddhhmm' => CHAN_PAIRING_ISSUANCE,
        'risk_levels' => $isOutline ? ['Moderate'] : null,
        'risk_level_polygons' => $isOutline ? CHAN_PAIRING_RISK_POLYGONS : null,
        ...$attributes,
    ]);
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

test('an areal outline that arrives a second before its outlook text is delivered once, as the outlook, with its risk areas', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    [$rule, $channel] = chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');

    chanPairingRunDueJobs();

    expect(chanPairingLogsFor($ptsdy1))->toBe(0)
        ->and(chanPairingLogsFor($swody1))->toBe(1)
        ->and(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);

    $this->travel(121)->seconds();
    chanPairingRunDueJobs();

    expect(DeliveryLog::where('alert_rule_id', $rule->id)->where('notification_channel_id', $channel->id)->sole()->ingested_product_id)->toBe($swody1->id)
        ->and($swody1->fresh()->risk_level_polygons)->toBe(CHAN_PAIRING_RISK_POLYGONS)
        ->and($swody1->fresh()->risk_levels)->toBe(['Moderate'])
        ->and(chanPairingQueuedJobNames())->toBe([]);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('an areal outline whose outlook text never arrives waits out the delay, then is delivered once on its own', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');

    chanPairingRunDueJobs();
    $this->travel(119)->seconds();
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

test('a pairing delay of zero matches an unpaired areal outline on its own at once, as before', function () {
    Mail::fake();
    $this->freezeTime();
    config(['wxnotify.outlook_pairing_delay_seconds' => 0]);
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');

    chanPairingRunDueJobs();

    expect(chanPairingLogsFor($ptsdy1))->toBe(1)
        ->and(chanPairingQueuedJobNames())->toBe([]);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('a test areal outline is matched on its own at once, without waiting', function () {
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    $ptsdy1 = chanPairingBulletin('ptsdy1', ['is_test' => true]);
    $matcher = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    chanPairingRunDueJobs();

    $matcher->shouldHaveReceived('__invoke')->once()->with(Mockery::on(fn (IngestedProduct $product): bool => $product->id === $ptsdy1->id));
    expect(chanPairingQueuedJobNames())->toBe([]);
});

test('an outlook text of another issuance is never paired with the waiting areal outline', function (Closure $otherOutlook) {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $other = $otherOutlook();

    chanPairingRunDueJobs();
    $this->travel(121)->seconds();
    chanPairingRunDueJobs();

    expect($other->fresh()->risk_level_polygons)->toBeNull()
        ->and(chanPairingLogsFor($ptsdy1))->toBe(1);
})->with([
    'a later issuance' => fn (): IngestedProduct => chanPairingBulletin('swody1', ['wmo_ddhhmm' => '071230']),
    'the same issuance time more than two hours away' => fn (): IngestedProduct => IngestedProduct::withoutEvents(
        fn (): IngestedProduct => chanPairingBulletin('swody1', ['created_at' => now()->subHours(3)]),
    ),
    'a replay copy of the outlook text' => fn (): IngestedProduct => IngestedProduct::withoutEvents(
        fn (): IngestedProduct => chanPairingBulletin('swody1', ['is_test' => true, 'replay_of_id' => chanPairingBulletin('swody1', ['wmo_ddhhmm' => '010000', 'created_at' => now()->subDays(5)])->id]),
    ),
]);

test('the delayed check is queued once per areal outline, however often its product is processed or the check is dispatched', function () {
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    ProcessIngestedProduct::dispatch($ptsdy1->id);

    chanPairingRunDueJobs();
    PairOutlookAreasAfterDelay::dispatch($ptsdy1->id);

    expect(chanPairingQueuedJobNames())->toBe([PairOutlookAreasAfterDelay::class]);
});

test('running the delayed check again after it paired sends nothing more and never matches the outline on its own', function () {
    Mail::fake();
    $this->freezeTime();
    chanPairingUseDatabaseQueue();
    chanPairingOutlookRule();
    $ptsdy1 = chanPairingBulletin('ptsdy1');
    $this->travel(1)->seconds();
    $swody1 = chanPairingBulletin('swody1');
    chanPairingRunDueJobs();
    $this->travel(121)->seconds();
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
    [$ptsdy1, $swody1] = IngestedProduct::withoutEvents(function (): array {
        $ptsdy1 = chanPairingBulletin('ptsdy1');
        $this->travel(1)->seconds();

        return [$ptsdy1, chanPairingBulletin('swody1')];
    });
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
    $ptsdy1 = IngestedProduct::withoutEvents(fn (): IngestedProduct => chanPairingBulletin('ptsdy1'));
    $matcher = Mockery::mock(MatchWeatherAlertRules::class);
    $matcher->shouldReceive('__invoke')->andThrow(new RuntimeException('matcher exploded'));
    app()->instance(MatchWeatherAlertRules::class, $matcher);

    (new PairOutlookAreasAfterDelay($ptsdy1->id))->handle();

    Log::shouldHaveReceived('error')->once()->with('PairOutlookAreasAfterDelay: matching the areal outline on its own failed', [
        'ingested_product_id' => $ptsdy1->id,
        'exception' => RuntimeException::class,
    ]);
});
