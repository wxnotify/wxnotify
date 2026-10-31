<?php

use App\Actions\Weather\BuildOutlookReport;
use App\Actions\Weather\FindActivePwo;
use App\Enums\MessageBodyStyle;
use App\Enums\OutlookReportMode;
use App\Enums\Tier;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

const DOR_BASE = 'www.spc.noaa.gov/products/';

function dorCollection(array $labels, string $issued): string
{
    return json_encode([
        'type' => 'FeatureCollection',
        'features' => array_map(fn (string $label): array => [
            'type' => 'Feature',
            'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-98.0, 35.0], [-96.0, 35.0], [-96.0, 37.0], [-98.0, 37.0], [-98.0, 35.0]]]],
            'properties' => ['LABEL' => $label, 'ISSUE_ISO' => $issued],
        ], $labels),
    ]);
}

/**
 * SPC files for every day. Day 1's category layer carries `$day1Cat` (empty is a quiet day) issued at
 * `$day1Issued`; every other layer and day is quiet.
 *
 * @return array<string, mixed>
 */
function dorFiles(array $day1Cat = ['TSTM', 'SLGT'], string $day1Issued = '2026-10-31T13:00:00Z'): array
{
    $headers = ['Content-Type' => 'application/geo+json', 'Last-Modified' => Carbon::parse($day1Issued)->toRfc7231String()];
    $quiet = Http::response(dorCollection([], $day1Issued), 200, $headers);
    $files = [DOR_BASE.'outlook/day1otlk_cat.nolyr.geojson' => Http::response(dorCollection($day1Cat, $day1Issued), 200, $headers)];

    foreach ([1, 2] as $day) {
        foreach (['cat', 'torn', 'hail', 'wind'] as $layer) {
            $files[DOR_BASE."outlook/day{$day}otlk_{$layer}.nolyr.geojson"] ??= $quiet;
        }
    }

    $files[DOR_BASE.'outlook/day3otlk_cat.nolyr.geojson'] = $quiet;
    $files[DOR_BASE.'outlook/day3otlk_prob.nolyr.geojson'] = $quiet;

    foreach ([4, 5, 6, 7, 8] as $day) {
        $files[DOR_BASE."exper/day4-8/day{$day}prob.nolyr.geojson"] = $quiet;
    }

    return $files;
}

/**
 * A Plus-tier subscriber: the outlook report is a paid-tier feature.
 */
function dorPaid(): UserFactory
{
    return User::factory()->state(['tier_override' => Tier::Plus]);
}

/**
 * An active outlook report rule sending at 14:30 UTC to one channel, with one location inside the fixture area.
 */
function dorRule(array $attributes = [], ?NotificationChannel $channel = null): AlertRule
{
    $user = $channel?->user ?? dorPaid()->create();
    $location = Location::factory()->for($user)->create(['label' => 'Home', 'lat' => 35.9, 'lng' => -97.0]);
    $rule = AlertRule::factory()->outlookReport()->for($user)->locations($location)->create($attributes);
    $rule->notificationChannels()->attach($channel ?? NotificationChannel::factory()->for($user)->create());

    return $rule;
}

afterEach(function () {
    Carbon::setTestNow();
});

test('a due rule sends the subject and brief once to its channel and stamps today', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $rule = dorRule();
    $channel = $rule->notificationChannels->sole();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->ruleId === $rule->id
        && $job->channelId === $channel->id
        && $job->title === 'Severe Wx Report - 🟡 D1 | ⚪ D2 | ⚪ D3 | ⚪ D4 | ⚪ D5 | ⚪ D6 | ⚪ D7 | ⚪ D8'
        && $job->body === 'D1 🟡  D2 ⚪  D3 ⚪  D4-8 ⚪'
        && $job->report === null
        && $job->batchKey !== null);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('a rule before its send time sends nothing and fetches nothing', function () {
    Carbon::setTestNow('2026-10-31 14:29:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $rule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a second run on the same UTC day sends nothing more', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();
    Carbon::setTestNow('2026-10-31 14:45:00');
    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
});

test('a stamp from yesterday sends today', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $rule = dorRule(['last_scheduled_run_on' => '2026-10-30']);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('an inactive rule is skipped without fetching', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $rule = dorRule(['is_active' => false]);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('two due rules share one SPC fetch', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    dorRule();
    dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 2);
    Http::assertSentCount(15);
});

test('a stale Day 1 within the grace hour sends nothing and leaves the stamp for the next tick', function () {
    Carbon::setTestNow('2026-10-31 14:35:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Issued: '2026-10-31T06:00:00Z'));
    $rule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a stale Day 1 after the grace hour is sent as fetched, not marked missing', function () {
    Carbon::setTestNow('2026-10-31 15:31:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Issued: '2026-10-31T06:00:00Z'));
    $rule = dorRule([], NotificationChannel::factory()->for(dorPaid())->email()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report['day1_missing'] === false
        && $job->report['days'][1]['category'] === 'slight');
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('a stale Day 1 is held until exactly 60 minutes after the send time', function () {
    Carbon::setTestNow('2026-10-31 15:29:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Issued: '2026-10-31T06:00:00Z'));
    $rule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();

    Carbon::setTestNow('2026-10-31 15:30:00');
    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('an unavailable Day 1 waits out the grace hour', function () {
    Carbon::setTestNow('2026-10-31 14:35:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake([DOR_BASE.'outlook/day1otlk_cat.nolyr.geojson' => Http::response('', 500)] + dorFiles());
    $rule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('an unavailable Day 1 after the grace hour sends with day1_missing', function () {
    Carbon::setTestNow('2026-10-31 15:31:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake([DOR_BASE.'outlook/day1otlk_cat.nolyr.geojson' => Http::response('', 500)] + dorFiles());
    dorRule([], NotificationChannel::factory()->for(dorPaid())->email()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report['day1_missing'] === true
        && str_starts_with($job->title, 'Severe Wx Report - ❔ D1 |'));
});

test('a failed Day 1 hail file holds the rule within the grace hour and sends it marked missing after', function () {
    Carbon::setTestNow('2026-10-31 14:35:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake([DOR_BASE.'outlook/day1otlk_hail.nolyr.geojson' => Http::response('', 503)] + dorFiles());
    $rule = dorRule([], NotificationChannel::factory()->for(dorPaid())->email()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();

    Carbon::setTestNow('2026-10-31 15:30:00');
    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report['days'][1]['missing'] === ['hail']
        && $job->report['day1_missing'] === false);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('an SPC outage after the grace hour still sends the unavailable report', function () {
    Carbon::setTestNow('2026-10-31 15:31:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(['www.spc.noaa.gov/*' => Http::response('', 500)]);
    $rule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->title === 'Severe Wx Report - ❔ D1 | ❔ D2 | ❔ D3 | ❔ D4 | ❔ D5 | ❔ D6 | ❔ D7 | ❔ D8'
        && $job->body === 'D1 ❔  D2 ❔  D3 ❔  D4-8 ❔');
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('only when forecast on a quiet day stamps without sending', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Cat: []));
    $rule = dorRule(['report_mode' => OutlookReportMode::OnlyWhenForecast]);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
});

test('only when forecast sends when something is forecast', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Cat: ['MRGL']));
    dorRule(['report_mode' => OutlookReportMode::OnlyWhenForecast]);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
});

test('only when forecast still sends when Day 1 is unavailable after the grace hour', function () {
    Carbon::setTestNow('2026-10-31 15:31:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake([DOR_BASE.'outlook/day1otlk_cat.nolyr.geojson' => Http::response('', 500)] + dorFiles(day1Cat: []));
    dorRule(['report_mode' => OutlookReportMode::OnlyWhenForecast]);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
});

test('a rule with a phone and an email channel passes the report to both jobs with one brief and one batch key', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $user = dorPaid()->create();
    $email = NotificationChannel::factory()->for($user)->email()->create();
    $phone = NotificationChannel::factory()->for($user)->mobilePush()->create();
    $rule = dorRule([], $email);
    $rule->notificationChannels()->attach($phone);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    $jobs = Queue::pushed(DeliverScheduledAlert::class);
    expect($jobs->pluck('channelId')->sort()->values()->all())->toBe(collect([$email->id, $phone->id])->sort()->values()->all())
        ->and($jobs->pluck('body')->unique()->all())->toBe(['D1 🟡  D2 ⚪  D3 ⚪  D4-8 ⚪'])
        ->and($jobs->pluck('batchKey')->unique())->toHaveCount(1)
        ->and($jobs->every(fn (DeliverScheduledAlert $job): bool => $job->report['days'][1]['locations'][0] === [
            'label' => 'Home', 'category' => 'slight', 'hail' => null, 'wind' => null, 'tornado' => null,
        ]))->toBeTrue();
});

test('a rule with only non-rich channels gets no report', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    dorRule([], NotificationChannel::factory()->for(dorPaid())->mobilePush()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report === null);
});

test('a failing rule is left unstamped, logged by class only, and does not stop the others', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    Log::spy();
    $failing = dorRule();
    $working = dorRule();
    $real = app(BuildOutlookReport::class);
    $calls = 0;
    $this->mock(BuildOutlookReport::class)->shouldReceive('__invoke')->andReturnUsing(function (...$arguments) use ($real, &$calls): array {
        if ($calls++ === 0) {
            throw new RuntimeException('secret detail');
        }

        return $real(...$arguments);
    });

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->ruleId === $working->id);
    Queue::assertNotPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->ruleId === $failing->id);
    expect($failing->fresh()->last_scheduled_run_on)->toBeNull()
        ->and($working->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-10-31');
    Log::shouldHaveReceived('warning')->with('Outlook report failed', ['rule_id' => $failing->id, 'exception' => RuntimeException::class]);
});

test('a Moderate Day 1 carries the active PWO in the report', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles(day1Cat: ['MDT']));
    $pwo = Product::factory()->create(['code' => 'PWO', 'name' => 'SPC Public Severe Weather Outlook (Statewide)']);
    IngestedProduct::factory()->for($pwo)->create(['text' => 'Tornadoes are likely.', 'received_at' => '2026-10-31 10:00:00']);
    dorRule([], NotificationChannel::factory()->for(dorPaid())->email()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report['pwo']['text'] === 'Tornadoes are likely.');
});

test('the active PWO is the newest non-test one received on or after the Day 1 issue date', function () {
    $pwo = Product::factory()->create(['code' => 'PWO']);
    $other = Product::factory()->create();
    IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-10-30 23:00:00']);
    $newest = IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-10-31 08:00:00']);
    IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-10-31 06:00:00']);
    IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-10-31 09:00:00', 'is_test' => true]);
    IngestedProduct::factory()->for($other)->create(['received_at' => '2026-10-31 10:00:00']);

    expect(app(FindActivePwo::class)('2026-10-31T13:00:00Z')?->id)->toBe($newest->id)
        ->and(app(FindActivePwo::class)('2026-11-01T01:00:00Z'))->toBeNull();
});

test('the active PWO falls back to the current UTC date without a Day 1 issue time', function () {
    Carbon::setTestNow('2026-11-01 02:00:00');
    $pwo = Product::factory()->create(['code' => 'PWO']);
    IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-10-31 23:00:00']);
    $today = IngestedProduct::factory()->for($pwo)->create(['received_at' => '2026-11-01 01:00:00']);

    expect(app(FindActivePwo::class)(null)?->id)->toBe($today->id)
        ->and(app(FindActivePwo::class)('not a date')?->id)->toBe($today->id);
});

test('no PWO catalog product means no active PWO', function () {
    Product::where('code', 'PWO')->delete();

    expect(app(FindActivePwo::class)('2026-10-31T13:00:00Z'))->toBeNull();
});

test('a rule whose owner is on the Free tier is skipped without fetching or stamping', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $free = User::factory()->create(['tier_override' => null]);
    $rule = dorRule([], NotificationChannel::factory()->for($free)->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a Free-owned rule does not stop a paid rule in the same run', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $freeRule = dorRule([], NotificationChannel::factory()->for(User::factory())->create());
    $paidRule = dorRule();

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, 1);
    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->ruleId === $paidRule->id);
    expect($freeRule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a Brief rule sends the brief to every channel with no report, even to email', function () {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    $user = dorPaid()->create();
    $email = NotificationChannel::factory()->for($user)->email()->create();
    $phone = NotificationChannel::factory()->for($user)->mobilePush()->create();
    $rule = dorRule(['message_body_style' => MessageBodyStyle::Brief], $email);
    $rule->notificationChannels()->attach($phone);

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    $jobs = Queue::pushed(DeliverScheduledAlert::class);
    expect($jobs)->toHaveCount(2)
        ->and($jobs->every(fn (DeliverScheduledAlert $job): bool => $job->report === null && $job->body === 'D1 🟡  D2 ⚪  D3 ⚪  D4-8 ⚪'))->toBeTrue();
});

test('a Full or unset rule still gives an email channel the report', function (?MessageBodyStyle $style) {
    Carbon::setTestNow('2026-10-31 14:30:00');
    Queue::fake([DeliverScheduledAlert::class]);
    Http::fake(dorFiles());
    dorRule(['message_body_style' => $style], NotificationChannel::factory()->for(dorPaid())->email()->create());

    $this->artisan('alerts:dispatch-outlook-reports')->assertSuccessful();

    Queue::assertPushed(DeliverScheduledAlert::class, fn (DeliverScheduledAlert $job): bool => $job->report !== null);
})->with([
    'full' => [MessageBodyStyle::Full],
    'unset' => [null],
]);
