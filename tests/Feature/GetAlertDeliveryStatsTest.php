<?php

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-02 18:00:00'); // 12:00 America/Denver, 18:00 UTC

    $this->user = User::factory()->create();
    $this->stats = new GetAlertDeliveryStats;
});

afterEach(function () {
    Carbon::setTestNow();
});

function logAt(User $user, string $utc, ?string $category = 'weather_alerts', string $status = 'success'): DeliveryLog
{
    return DeliveryLog::factory()->for($user)->create(['dispatched_at' => $utc, 'category' => $category, 'status' => $status]);
}

test('the timezone is the most recently added location\'s, falling back to UTC', function () {
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');

    Location::factory()->for($this->user)->create(['timezone' => 'America/New_York', 'created_at' => now()->subDays(2)]);
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver', 'created_at' => now()->subDay()]);

    expect($this->stats->timezoneFor($this->user))->toBe('America/Denver');
});

test('a missing or invalid stored timezone falls back to UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => null]);
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');

    Location::factory()->for($this->user)->create(['timezone' => 'Mars/Olympus_Mons', 'created_at' => now()->addMinute()]);
    expect($this->stats->timezoneFor($this->user))->toBe('UTC');
});

test('per-day returns exactly the requested days, zero-filled, oldest first, ending today', function () {
    logAt($this->user, '2026-10-02 10:00:00');
    logAt($this->user, '2026-10-02 11:00:00');
    logAt($this->user, '2026-09-30 10:00:00');

    $result = $this->stats->perDay($this->user, 7);

    expect($result['labels'])->toBe(['Sep 26', 'Sep 27', 'Sep 28', 'Sep 29', 'Sep 30', 'Oct 1', 'Oct 2'])
        ->and($result['counts'])->toBe([0, 0, 0, 0, 1, 0, 2])
        ->and($result['total'])->toBe(3);
});

test('only this subscriber\'s successful deliveries are counted', function () {
    logAt($this->user, '2026-10-02 10:00:00');
    logAt($this->user, '2026-10-02 10:00:00', status: 'failure');
    logAt($this->user, '2026-10-02 10:00:00', status: 'suppressed');
    logAt($this->user, '2026-10-02 10:00:00', status: 'skipped');
    logAt(User::factory()->create(), '2026-10-02 10:00:00');

    expect($this->stats->perDay($this->user, 7)['total'])->toBe(1)
        ->and($this->stats->byCategory($this->user, 7))->toHaveCount(1);
});

test('rows older than the range are excluded, and the range boundary is the start of the first local day', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    // Range of 7 days in Denver starts 2026-09-26 00:00 Denver = 2026-09-26 06:00 UTC.
    logAt($this->user, '2026-09-26 05:59:59'); // 2026-09-25 23:59:59 Denver: outside
    logAt($this->user, '2026-09-26 06:00:00'); // 2026-09-26 00:00:00 Denver: inside

    $result = $this->stats->perDay($this->user, 7);

    expect($result['total'])->toBe(1)->and($result['counts'][0])->toBe(1);
});

test('a delivery just after midnight UTC counts on the previous local day for a Denver subscriber', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    logAt($this->user, '2026-10-02 02:00:00'); // 2026-10-01 20:00 Denver

    $result = $this->stats->perDay($this->user, 7);

    expect($result['counts'][5])->toBe(1)   // Oct 1
        ->and($result['counts'][6])->toBe(0); // Oct 2
});

test('the same delivery counts on the UTC day for a subscriber with no location', function () {
    logAt($this->user, '2026-10-02 02:00:00');

    $result = $this->stats->perDay($this->user, 7);

    expect($result['counts'][6])->toBe(1)->and($result['counts'][5])->toBe(0);
});

test('by-category groups, orders by count then label, and puts Other last', function () {
    foreach (range(1, 3) as $i) {
        logAt($this->user, '2026-10-02 10:00:00', 'weather_alerts');
    }
    foreach (range(1, 2) as $i) {
        logAt($this->user, '2026-10-02 10:00:00', 'threshold');
    }
    foreach (range(1, 2) as $i) {
        logAt($this->user, '2026-10-02 10:00:00', 'daily_summary');
    }
    logAt($this->user, '2026-10-02 10:00:00', 'lightning');
    logAt($this->user, '2026-10-02 10:00:00', null);
    logAt($this->user, '2026-10-02 10:00:00', 'forecast_discussion'); // a removed category: counts as Other

    expect($this->stats->byCategory($this->user, 7))->toBe([
        ['category' => 'weather_alerts', 'label' => 'Weather Alerts', 'count' => 3],
        ['category' => 'daily_summary', 'label' => 'Daily Weather Summary', 'count' => 2],
        ['category' => 'threshold', 'label' => 'Threshold Alerts', 'count' => 2],
        ['category' => 'lightning', 'label' => 'Lightning Alerts', 'count' => 1],
        ['category' => null, 'label' => 'Other', 'count' => 2],
    ]);
});

test('by-category is empty when there is nothing in the range, and honours the range', function () {
    expect($this->stats->byCategory($this->user, 30))->toBe([]);

    logAt($this->user, '2026-09-01 10:00:00'); // 31 days ago: outside 30, inside 90

    expect($this->stats->byCategory($this->user, 30))->toBe([])
        ->and($this->stats->byCategory($this->user, 90))->toHaveCount(1);
});

test('the profile timezone beats the newest location', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/New_York']);
    $this->user->update(['timezone' => 'Pacific/Honolulu']);

    expect($this->stats->timezoneFor($this->user->fresh()))->toBe('Pacific/Honolulu');
});

test('without a profile timezone a newer location that has no timezone does not flip the charts to UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver', 'created_at' => now()->subDay()]);
    Location::factory()->for($this->user)->create(['timezone' => null, 'created_at' => now()]); // e.g. a mobile location

    expect($this->stats->timezoneFor($this->user))->toBe('America/Denver');
});

test('an invalid profile timezone is ignored', function () {
    Location::factory()->for($this->user)->create(['timezone' => 'America/Denver']);
    $this->user->forceFill(['timezone' => 'Mars/Olympus_Mons'])->save();

    expect($this->stats->timezoneFor($this->user->fresh()))->toBe('America/Denver');
});

test('with no profile timezone and no location timezone the charts use UTC', function () {
    Location::factory()->for($this->user)->create(['timezone' => null]);

    expect($this->stats->timezoneFor($this->user))->toBe('UTC');
});

test('a profile timezone moves the day boundary of the per-day counts', function () {
    $this->user->update(['timezone' => 'America/Denver']);
    logAt($this->user, '2026-10-02 02:00:00'); // 2026-10-01 20:00 Denver

    $result = $this->stats->perDay($this->user->fresh(), 7);

    expect($result['counts'][5])->toBe(1)->and($result['counts'][6])->toBe(0);
});
