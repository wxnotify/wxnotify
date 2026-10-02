<?php

use App\Actions\Weather\BuildDailySummaryReport;
use App\Enums\Tier;
use App\Enums\UnitSystem;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function fakeGridDataForThreshold(float $overnightLow): void
{
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => [
                'forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast',
                'forecastGridData' => 'https://api.weather.gov/gridpoints/TOP/1,1',
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1/forecast' => Http::response([
            'properties' => [
                'periods' => [
                    ['isDaytime' => true, 'startTime' => '2026-09-14T06:00:00-05:00', 'endTime' => '2026-09-14T18:00:00-05:00'],
                    ['isDaytime' => false, 'startTime' => '2026-09-14T18:00:00-05:00', 'endTime' => '2026-09-15T06:00:00-05:00'],
                ],
            ],
        ]),
        'api.weather.gov/gridpoints/TOP/1,1' => Http::response([
            'properties' => [
                'temperature' => ['values' => [
                    ['validTime' => '2026-09-15T02:00:00-05:00/PT1H', 'value' => ($overnightLow - 32) * 5 / 9],
                ]],
            ],
        ]),
    ]);
}

beforeEach(function () {
    // 2026-09-14 12:00 UTC = 07:00 America/Chicago (UTC-5, CDT) — safely
    // past a 06:00 delivery_time there — and 02:00 Pacific/Honolulu
    // (UTC-10, no DST) — safely before one.
    Carbon::setTestNow('2026-09-14 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

test('a threshold rule due now dispatches when the forecast crosses it', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);

    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create([
        'threshold_value' => 32,
        'delivery_time' => '06:00',
    ]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->channelId === $channel->id
        && str_contains($job->body, '27'));

    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-09-14');
});

test('a threshold rule not yet crossed is checked but nothing is dispatched', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 40.0);

    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00']);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
    expect($rule->fresh()->last_scheduled_run_on->toDateString())->toBe('2026-09-14');
});

test('a rule already run today is not evaluated again', function () {
    Bus::fake();
    Http::fake(); // any real call here would be a bug

    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'America/Chicago']);
    AlertRule::factory()->for($user)->for($location)->create([
        'delivery_time' => '06:00',
        'last_scheduled_run_on' => '2026-09-14',
    ]);

    $this->artisan('alerts:dispatch-scheduled');

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

test('a rule whose delivery_time has not arrived yet in its own timezone is skipped', function () {
    Bus::fake();
    Http::fake();

    // It's noon UTC per beforeEach, but this location is far enough west
    // (Pacific/Honolulu, UTC-10) that 06:00 local hasn't happened yet.
    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'Pacific/Honolulu']);
    AlertRule::factory()->for($user)->for($location)->create(['delivery_time' => '06:00']);

    $this->artisan('alerts:dispatch-scheduled');

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

test('a gridpoint data failure leaves last_scheduled_run_on unset so the next tick retries', function () {
    Bus::fake();
    Http::fake(['api.weather.gov/points/*' => Http::response([], 503)]);

    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['delivery_time' => '06:00']);

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertNothingDispatched();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a Threshold rule against a fixless mobile location is skipped, not crashed', function () {
    $location = Location::factory()->mobile()->create();
    $rule = AlertRule::factory()->create([
        'category' => 'threshold', 'location_id' => $location->id, 'delivery_time' => '06:00',
        'variable' => 'temperature', 'direction' => 'above', 'threshold_value' => 90, 'timeframe' => 'day',
    ]);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    Http::fake();
    Bus::fake();

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertNothingDispatched();
    Http::assertNothingSent();
    expect($rule->fresh()->last_scheduled_run_on)->toBeNull();
});

test('a daily summary rule dispatches an unconditional message', function () {
    Bus::fake();
    Http::fake([
        'api.weather.gov/points/*' => Http::response([
            'properties' => ['forecast' => 'https://api.weather.gov/gridpoints/TOP/1,1/forecast'],
        ]),
        'api.weather.gov/gridpoints/*' => Http::response([
            'properties' => ['periods' => [
                ['name' => 'Today', 'isDaytime' => true, 'temperature' => 80, 'temperatureUnit' => 'F', 'shortForecast' => 'Sunny'],
            ]],
        ]),
    ]);

    $location = Location::factory()->for($user = User::factory()->create())->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->dailySummary()->for($user)->for($location)->create(['delivery_time' => '06:00']);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $rule->id && str_contains($job->body, 'Sunny'));
});

function summaryRule(string $style, array $channels): AlertRule
{
    $user = User::factory()->create(['tier_override' => Tier::Plus]);
    $location = Location::factory()->for($user)->create(['label' => 'Home', 'timezone' => 'America/Denver', 'lat' => 40.0031694, 'lng' => -104.997011]);
    $rule = AlertRule::factory()->dailySummary()->for($user)->for($location)->create(['delivery_time' => '06:00', 'message_body_style' => $style]);

    foreach ($channels as $channel) {
        $rule->notificationChannels()->attach($channel->for($user)->create());
    }

    return $rule;
}

test('a Full daily summary rule carries the structured report on each dispatched job', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com'), NotificationChannel::factory()]);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatchedTimes(DeliverScheduledAlert::class, 2);
    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $rule->id
        && is_array($job->report)
        && $job->report['location'] === 'Home'
        && count($job->report['periods']) === 3
        && str_contains($job->body, 'Sunny'));
});

test('a Brief daily summary rule dispatches with no report', function () {
    Bus::fake();
    nwsFakeEverything();
    summaryRule('brief', [NotificationChannel::factory()->email('a@example.com')]);

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});

test('a rule with no stored message style (Free, or created before the option) dispatches with no report', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);
    $rule->user->forceFill(['tier_override' => Tier::Free])->save();

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});

test('the report is not built when no attached channel can render it', function () {
    Bus::fake();
    nwsFakeEverything();
    summaryRule('full', [NotificationChannel::factory()]); // an apprise channel only

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/observations/latest') || str_contains($request->url(), 'radar.weather.gov'));
});

test('a failure while building the report still delivers the Brief summary', function () {
    Bus::fake();
    nwsFakeEverything();
    app()->bind(BuildDailySummaryReport::class, fn () => new class extends BuildDailySummaryReport
    {
        public function __invoke(Location $location, bool $includeTestAlerts = false, UnitSystem $units = UnitSystem::Imperial): ?array
        {
            throw new RuntimeException('boom');
        }
    });
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null && str_contains($job->body, 'Sunny'));
    expect($rule->fresh()->last_scheduled_run_on)->not->toBeNull();
});

test('a Threshold rule never gets a report', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);
    $location = Location::factory()->for($user = User::factory()->create(['tier_override' => Tier::Plus]))->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00', 'message_body_style' => 'full']);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->email('a@example.com')->create());

    $this->artisan('alerts:dispatch-scheduled');

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report === null);
});

test('a metric subscriber\'s threshold alert message is in celsius', function () {
    Bus::fake();
    fakeGridDataForThreshold(overnightLow: 27.0);

    $location = Location::factory()->for($user = User::factory()->create(['unit_system' => 'metric']))->create(['timezone' => 'America/Chicago']);
    $rule = AlertRule::factory()->for($user)->for($location)->create(['threshold_value' => 32, 'delivery_time' => '06:00']);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create());

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => str_contains($job->body, 'below 0°C')
        && str_contains($job->body, 'peak -2.8°C')
        && ! str_contains($job->body, '°F'));
});

test('a metric subscriber\'s Brief daily summary and report are built in metric', function () {
    Bus::fake();
    nwsFakeEverything();
    $rule = summaryRule('full', [NotificationChannel::factory()->email('a@example.com')]);
    $rule->user->update(['unit_system' => 'metric']);

    $this->artisan('alerts:dispatch-scheduled')->assertSuccessful();

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->report['units'] === 'metric'
        && str_contains($job->body, '°C')
        && ! str_contains($job->body, '°F'));
});
