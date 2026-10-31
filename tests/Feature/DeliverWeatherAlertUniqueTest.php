<?php

use App\Jobs\DeliverWeatherAlert;
use App\Jobs\ProcessIngestedProduct;
use App\Mail\ChannelMessageMail;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * These tests queue onto the real database driver (not the sync connection the suite defaults to,
 * and not Queue::fake()) so a delivery job sits in the jobs table on its `deliveries` queue until the
 * worker reaches it, as on the production worker, and ShouldBeUnique's lock is taken at dispatch and
 * released by the worker.
 */
const CHAN_UNIQUE_RISK_POLYGONS = [
    'Moderate' => [['lat' => 33, 'lon' => -97], ['lat' => 33, 'lon' => -94], ['lat' => 36, 'lon' => -94], ['lat' => 36, 'lon' => -97]],
];

function chanUseDatabaseQueue(): void
{
    config(['queue.default' => 'database']);
}

/**
 * Runs every queued job the way the production worker does (one try each): by default the `deliveries`
 * queue first, then `default`, oldest first within each. The memory cap is lifted because a parallel test
 * process has already grown past the worker's 128 MB default, which would stop it after the first job; no
 * timeout keeps its alarm signal out of the test process.
 */
function chanRunQueuedJobs(string $queues = 'deliveries,default'): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => $queues, '--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0, '--timeout' => 0, '--memory' => 1_000_000]);
}

/**
 * An SPC & WPC rule watching the SPC Convective Outlook nationwide, with one verified email channel.
 *
 * @return array{0: AlertRule, 1: NotificationChannel}
 */
function chanOutlookRuleWithEmailChannel(): array
{
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach(Product::where('code', 'SPC.OUTLOOK')->sole());
    $channel = NotificationChannel::factory()->for($rule->user)->email('subscriber@example.com')->create();
    $rule->notificationChannels()->attach($channel);

    return [$rule, $channel];
}

function chanQueuedJobCount(): int
{
    return DB::table('jobs')->count();
}

test('an SPC Day 1 outlook re-matched by its areal outline before the first delivery ran is delivered once', function () {
    Mail::fake();
    $this->freezeTime();
    // No pairing delay, so the outlook text does not take the outline's risk areas before its own match
    // and the outline's backfill still re-matches it while the first delivery is queued.
    config(['wxnotify.outlook_pairing_delay_seconds' => 0]);
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $outlookId = Product::where('code', 'SPC.OUTLOOK')->sole()->id;
    $swody1 = IngestedProduct::factory()->create(['product_id' => $outlookId, 'awipsid' => 'swody1', 'wmo_header_key' => 'acus01 kwns 250102 swody1', 'risk_level_polygons' => null]);
    IngestedProduct::factory()->create(['product_id' => $outlookId, 'awipsid' => 'ptsdy1', 'wmo_header_key' => 'wuus01 kwns 250102 ptsdy1', 'risk_levels' => ['Moderate'], 'risk_level_polygons' => CHAN_UNIQUE_RISK_POLYGONS]);
    expect(DB::table('jobs')->pluck('payload')->map(fn (string $payload): string => json_decode($payload, true)['displayName'])->all())
        ->toBe([ProcessIngestedProduct::class, ProcessIngestedProduct::class]);

    // Both products are processed before any delivery runs (more than one worker, or deliveries queued
    // behind a running job), so the re-match finds the first delivery still queued.
    chanRunQueuedJobs('default');
    chanRunQueuedJobs('deliveries');

    expect(DeliveryLog::where('alert_rule_id', $rule->id)->where('notification_channel_id', $channel->id)->where('ingested_product_id', $swody1->id)->count())->toBe(1);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('a second dispatch for the same rule, channel and product is dropped while the first is still queued, and the first one\'s location and segments win', function () {
    Mail::fake();
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, null, []);
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id, 999, [12345]);

    expect(chanQueuedJobCount())->toBe(1);
    $queued = unserialize(json_decode(DB::table('jobs')->value('payload'), true)['data']['command']);
    expect($queued)->locationId->toBeNull()
        ->segmentIds->toBe([]);
});

test('a dispatch arriving after the first delivery finished is skipped by the job itself', function () {
    Mail::fake();
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    chanRunQueuedJobs();

    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    expect(chanQueuedJobCount())->toBe(1);
    chanRunQueuedJobs();

    expect(DeliveryLog::count())->toBe(1);
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('a different channel, product or rule is delivered independently', function () {
    Mail::fake();
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    [$otherRule, $otherRuleChannel] = chanOutlookRuleWithEmailChannel();
    $otherChannel = NotificationChannel::factory()->for($rule->user)->email('second@example.com')->create();
    $rule->notificationChannels()->attach($otherChannel);
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    $otherProduct = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());

    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    DeliverWeatherAlert::dispatch($rule->id, $otherChannel->id, $product->id);
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $otherProduct->id);
    DeliverWeatherAlert::dispatch($otherRule->id, $otherRuleChannel->id, $product->id);
    chanRunQueuedJobs();

    expect(DeliveryLog::where('status', 'success')->count())->toBe(4);
    Mail::assertSent(ChannelMessageMail::class, 4);
});

test('a delivery job that throws releases its lock, so a later retry is queued and delivers', function () {
    Mail::fake();
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    $this->mock(ChannelDispatcher::class)->shouldReceive('send')->once()->andThrow(new RuntimeException('transport exploded'));
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    chanRunQueuedJobs();
    expect(DB::table('failed_jobs')->count())->toBe(1);
    app()->forgetInstance(ChannelDispatcher::class);

    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    expect(chanQueuedJobCount())->toBe(1);
    chanRunQueuedJobs();

    expect(DeliveryLog::sole()->status)->toBe('success');
    Mail::assertSent(ChannelMessageMail::class, 1);
});

test('a lock left behind by a crashed worker expires after an hour', function () {
    chanUseDatabaseQueue();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    DB::table('jobs')->delete();

    $this->travel(59)->minutes();
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);
    expect(chanQueuedJobCount())->toBe(0);
    $this->travel(2)->minutes();
    DeliverWeatherAlert::dispatch($rule->id, $channel->id, $product->id);

    expect(chanQueuedJobCount())->toBe(1);
});

test('the job sends nothing and writes nothing when the rule, channel and product already have a delivery row of any status', function (string $status) {
    Mail::fake();
    Log::spy();
    [$rule, $channel] = chanOutlookRuleWithEmailChannel();
    $product = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create());
    DeliveryLog::factory()->create([
        'alert_rule_id' => $rule->id,
        'notification_channel_id' => $channel->id,
        'ingested_product_id' => $product->id,
        'user_id' => $rule->user_id,
        'channel_type' => $channel->channel_type,
        'status' => $status,
    ]);

    (new DeliverWeatherAlert($rule->id, $channel->id, $product->id))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::count())->toBe(1);
    Mail::assertNothingSent();
    Log::shouldHaveReceived('debug')->once()->with('DeliverWeatherAlert: already delivered for this rule, channel and product', [
        'rule_id' => $rule->id,
        'channel_id' => $channel->id,
        'ingested_product_id' => $product->id,
    ]);
})->with(['success', 'failure', 'skipped', 'suppressed']);

test('the unique lock key is the rule, channel and product ids only, never the location, segments or any personal data', function () {
    $job = new DeliverWeatherAlert(5, 6, 42, 7, [1, 2]);

    expect(UniqueLock::getKey($job))->toBe('laravel_unique_job:App\Jobs\DeliverWeatherAlert:5:6:42');
});
