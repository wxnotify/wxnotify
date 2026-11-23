<?php

use App\Actions\Alerts\GetAlertStats;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $settings = app(TierThresholdSettings::class);
    $settings->free_alerts = 50; // these fixtures hold more alerts than the plan limit
    $settings->save();
    $this->user = User::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function statsDelivery(User $user, string $status, string|Carbon $when): DeliveryLog
{
    return DeliveryLog::factory()->for($user)->create(['status' => $status, 'dispatched_at' => $when, 'category' => 'weather_alerts']);
}

test('active and total alert counts', function () {
    AlertRule::factory()->for($this->user)->count(3)->create(['is_active' => true]);
    AlertRule::factory()->for($this->user)->count(2)->create(['is_active' => false]);
    AlertRule::factory()->for(User::factory())->create(['is_active' => true]); // someone else's

    $stats = (new GetAlertStats)($this->user);

    expect($stats['active'])->toBe(3)->and($stats['total'])->toBe(5);
});

test('deliveries, failures and quiet-hours holds are counted over a rolling 7 days', function () {
    statsDelivery($this->user, 'success', now()->subDays(6)->subHours(23)); // inside
    statsDelivery($this->user, 'success', now()->subHour());                 // inside
    statsDelivery($this->user, 'success', now()->subDays(7)->subHour());     // just outside
    statsDelivery($this->user, 'failure', now()->subDays(2));
    statsDelivery($this->user, 'failure', now()->subDays(9));                // outside
    statsDelivery($this->user, 'suppressed', now()->subDays(3));
    statsDelivery($this->user, 'skipped', now()->subDays(3));                // not counted anywhere

    $stats = (new GetAlertStats)($this->user);

    expect($stats['deliveries'])->toBe(2)
        ->and($stats['failures'])->toBe(1)
        ->and($stats['held'])->toBe(1);
});

test('the previous 7 days are counted for the trend', function () {
    statsDelivery($this->user, 'success', now()->subDays(8));
    statsDelivery($this->user, 'success', now()->subDays(13));
    statsDelivery($this->user, 'success', now()->subDays(15)); // older than 14 days
    statsDelivery($this->user, 'success', now()->subDay());    // current window

    $stats = (new GetAlertStats)($this->user);

    expect($stats['previous_deliveries'])->toBe(2)->and($stats['deliveries'])->toBe(1);
});

test('the window boundaries are exact: now-7d is current, now-14d is previous, just older is neither', function () {
    statsDelivery($this->user, 'success', now()->subDays(7));                  // exactly 7 days: current
    statsDelivery($this->user, 'success', now()->subDays(7)->subSecond());     // just before: previous
    statsDelivery($this->user, 'success', now()->subDays(14));                 // exactly 14 days: previous
    statsDelivery($this->user, 'success', now()->subDays(14)->subSecond());    // older: neither

    $stats = (new GetAlertStats)($this->user);

    expect($stats['deliveries'])->toBe(1)->and($stats['previous_deliveries'])->toBe(2);
});

test('failures and holds in the previous window are not counted', function () {
    statsDelivery($this->user, 'failure', now()->subDays(8));
    statsDelivery($this->user, 'suppressed', now()->subDays(10));
    statsDelivery($this->user, 'failure', now()->subDays(7));   // exactly at the boundary: current
    statsDelivery($this->user, 'suppressed', now()->subDay());

    $stats = (new GetAlertStats)($this->user);

    expect($stats['failures'])->toBe(1)->and($stats['held'])->toBe(1);
});

test('only this subscriber\'s deliveries are counted', function () {
    statsDelivery($this->user, 'success', now()->subHour());
    statsDelivery(User::factory()->create(), 'success', now()->subHour());
    statsDelivery(User::factory()->create(), 'failure', now()->subHour());

    $stats = (new GetAlertStats)($this->user);

    expect($stats['deliveries'])->toBe(1)->and($stats['failures'])->toBe(0);
});

test('the sparkline is the last 7 days of successes, oldest first', function () {
    statsDelivery($this->user, 'success', now()->subHour());
    statsDelivery($this->user, 'success', now()->subHours(2));
    statsDelivery($this->user, 'success', now()->subDays(2));

    $daily = (new GetAlertStats)($this->user)['daily'];

    expect($daily)->toHaveCount(7)->and(array_sum($daily))->toBe(3)->and($daily[6])->toBe(2)->and($daily[4])->toBe(1);
});

test('a subscriber with nothing gets zeros', function () {
    expect((new GetAlertStats)($this->user))->toBe([
        'active' => 0, 'total' => 0, 'deliveries' => 0, 'previous_deliveries' => 0, 'failures' => 0, 'held' => 0,
        'daily' => [0, 0, 0, 0, 0, 0, 0],
    ]);
});

test('the number of queries does not depend on how much data there is', function () {
    $count = function (int $rows): int {
        DeliveryLog::query()->where('user_id', $this->user->id)->delete();
        foreach (range(1, $rows) as $i) {
            statsDelivery($this->user, 'success', now()->subHours($i));
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        (new GetAlertStats)($this->user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    expect($count(30))->toBe($count(1));
});
