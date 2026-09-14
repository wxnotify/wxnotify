<?php

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
