<?php

use App\Actions\Alerts\GetAlertsNeedingAttention;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Settings\TierThresholdSettings;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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

function attentionRows(User $user): array
{
    return (new GetAlertsNeedingAttention)($user);
}

/** @return array<int, string> "kind: subject" for each row, in order */
function attentionSummary(User $user): array
{
    return array_map(fn (array $row): string => "{$row['kind']}: {$row['subject']}", attentionRows($user));
}

function channelAttempt(User $user, NotificationChannel $channel, string $status, string|Carbon $when): void
{
    DeliveryLog::factory()->for($user)->create([
        'notification_channel_id' => $channel->id, 'status' => $status, 'dispatched_at' => $when, 'error_detail' => 'secret-destination https://hooks.example.com/token',
    ]);
}

function attentionAlert(User $user, array $channels = [], array $attributes = []): AlertRule
{
    $alert = AlertRule::factory()->dailySummary()->for($user)->for(Location::factory()->for($user)->create())->create($attributes);
    $alert->notificationChannels()->attach(collect($channels)->pluck('id'));

    return $alert;
}

/** An active mobile-location alert that has a channel, so only its location problem shows. */
function channeledAlert(User $user, Location $location, Factory $factory, array $attributes = []): AlertRule
{
    $alert = $factory->for($user)->for($location)->create($attributes);
    $alert->notificationChannels()->attach(NotificationChannel::factory()->for($user)->create()->id);

    return $alert;
}

test('a channel whose latest attempt in 7 days failed is reported with a count and a relative time', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    channelAttempt($this->user, $channel, 'failure', now()->subDays(2));
    channelAttempt($this->user, $channel, 'failure', now()->subHours(3));
    channelAttempt($this->user, $channel, 'success', now()->subDays(3));

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'channel_failing', 'severity' => 'danger', 'subject' => 'My Discord', 'target' => ['type' => 'channels', 'id' => null]])
        ->and($rows[0]['detail'])->toBe('its last delivery failed 3 hours ago (2 failures in 7 days): This delivery did not go through.')
        ->and(json_encode($rows))->not->toContain('secret-destination')->not->toContain('hooks.example.com');
});

test('a failing channel says why in one of the fixed sentences, taken from its latest failure', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My webhook']);
    DeliveryLog::factory()->for($this->user)->create(['notification_channel_id' => $channel->id, 'status' => 'failure', 'dispatched_at' => now()->subDays(2), 'error_detail' => 'Email delivery is turned off on this server.']);
    DeliveryLog::factory()->for($this->user)->create(['notification_channel_id' => $channel->id, 'status' => 'failure', 'dispatched_at' => now()->subHours(3), 'error_detail' => 'Webhook delivery failed: the endpoint returned HTTP 404.']);

    expect(attentionRows($this->user)[0]['detail'])->toBe('its last delivery failed 3 hours ago (2 failures in 7 days): Your endpoint refused the message.');
});

test('a success after the failures clears the channel; old failures do not count', function () {
    $recovered = NotificationChannel::factory()->for($this->user)->create(['label' => 'Recovered']);
    channelAttempt($this->user, $recovered, 'failure', now()->subHours(5));
    channelAttempt($this->user, $recovered, 'success', now()->subHour());

    $old = NotificationChannel::factory()->for($this->user)->create(['label' => 'Old trouble']);
    channelAttempt($this->user, $old, 'failure', now()->subDays(8));

    expect(attentionRows($this->user))->toBe([]);
});

test('skipped and suppressed attempts do not make a channel failing or healthy', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'My Discord']);
    channelAttempt($this->user, $channel, 'failure', now()->subHours(6));
    channelAttempt($this->user, $channel, 'suppressed', now()->subHour());
    channelAttempt($this->user, $channel, 'skipped', now()->subMinutes(10));

    expect(attentionSummary($this->user))->toBe(['channel_failing: My Discord']);
});

test('an unverified channel used by an active alert is reported with up to three alert names', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Work email', 'verified_at' => null]);
    foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $name) {
        attentionAlert($this->user, [$channel], ['name' => $name]);
    }

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'channel_unverified', 'severity' => 'warning', 'subject' => 'Work email'])
        ->and($rows[0]['detail'])->toBe("isn't confirmed yet — alerts using it won't be sent until you confirm it (Alpha, Bravo, Charlie and 1 more)");
});

test('an alert on a verified and an unverified channel lists only the unverified one', function () {
    $verified = NotificationChannel::factory()->for($this->user)->create(['label' => 'Verified email', 'verified_at' => now()]);
    $unverified = NotificationChannel::factory()->for($this->user)->create(['label' => 'Pending SMS', 'verified_at' => null]);
    attentionAlert($this->user, [$verified, $unverified], ['name' => 'Both channels']);

    expect(attentionSummary($this->user))->toBe(['channel_unverified: Pending SMS']);
});

test('failing channels are sorted by label without regard to case', function () {
    foreach (['banana', 'Cherry', 'apple'] as $label) {
        channelAttempt($this->user, NotificationChannel::factory()->for($this->user)->create(['label' => $label]), 'failure', now()->subHour());
    }

    expect(attentionSummary($this->user))->toBe(['channel_failing: apple', 'channel_failing: banana', 'channel_failing: Cherry']);
});

test('an unverified channel that no active alert uses is not reported', function () {
    NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
    $used = NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
    attentionAlert($this->user, [$used], ['is_active' => false, 'name' => 'Paused']);

    expect(array_column(attentionRows($this->user), 'kind'))->toBe(['alert_inactive']);
});

test('a channel that is both failing and unverified appears once, as unverified', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Both', 'verified_at' => null]);
    attentionAlert($this->user, [$channel]);
    channelAttempt($this->user, $channel, 'failure', now()->subHour());

    expect(attentionSummary($this->user))->toBe(['channel_unverified: Both']);
});

test('an unverified channel whose alerts the dispatcher refused is reported as unverified, not failing', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->for($this->user)->create(['label' => 'Pending Discord', 'verified_at' => null]);
    $alert = attentionAlert($this->user, [$channel], ['name' => 'Morning summary']);

    (new DeliverScheduledAlert($alert->id, $channel->id, 'Daily summary', 'Today: Sunny.'))->handle(app(ChannelDispatcher::class));

    expect(DeliveryLog::sole()->only(['status', 'error_detail']))->toBe(['status' => 'failure', 'error_detail' => 'This channel has not been confirmed yet.'])
        ->and(attentionSummary($this->user))->toBe(['channel_unverified: Pending Discord']);
    Http::assertNothingSent();
});

test('an active scheduled alert on a mobile location with no position is reported', function () {
    $truck = Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']);
    foreach (['daily' => AlertRule::factory()->dailySummary(), 'lightning' => AlertRule::factory()->lightning(), 'threshold' => AlertRule::factory()] as $name => $factory) {
        channeledAlert($this->user, $truck, $factory, ['name' => "{$name} alert"]);
    }

    $rows = attentionRows($this->user);

    expect(array_column($rows, 'kind'))->toBe(['location_no_position', 'location_no_position', 'location_no_position'])
        ->and($rows[0])->toMatchArray(['severity' => 'warning', 'detail' => 'is waiting for Truck to report a position', 'target' => ['type' => 'location', 'id' => $truck->id]]);
});

test('an active scheduled alert on a mobile location whose device is gone is a device problem, replacing the position row', function () {
    $gone = Location::factory()->mobile()->withFix(40.0, -105.0)->for($this->user)->create(['label' => 'Truck', 'traccar_device_missing_at' => now()->subDay()]);
    $goneNoFix = Location::factory()->mobile()->for($this->user)->create(['label' => 'Van', 'traccar_device_missing_at' => now()->subDay()]);
    $waiting = Location::factory()->mobile()->for($this->user)->create(['label' => 'Bike']);
    channeledAlert($this->user, $gone, AlertRule::factory()->dailySummary(), ['name' => 'Truck summary']);
    channeledAlert($this->user, $goneNoFix, AlertRule::factory()->dailySummary(), ['name' => 'Van summary']);
    channeledAlert($this->user, $waiting, AlertRule::factory()->dailySummary(), ['name' => 'Bike summary']);

    $rows = attentionRows($this->user);

    expect(array_map(fn (array $row): string => "{$row['kind']}: {$row['subject']}", $rows))->toBe([
        'location_device_missing: Truck summary',
        'location_device_missing: Van summary',
        'location_no_position: Bike summary',
    ])->and($rows[0])->toMatchArray([
        'severity' => 'warning',
        'detail' => "can't follow Truck: its Traccar device no longer exists on the server",
        'target' => ['type' => 'location', 'id' => $gone->id],
    ]);
});

test('an unlinked app location is a device problem reported once, while a linked one without a position is a position problem', function () {
    $token = $this->user->createToken('Pixel 9', ['app'])->accessToken;
    $unlinked = Location::factory()->mobileApp()->for($this->user)->create(['label' => 'Phone']);
    $linked = Location::factory()->mobileApp($token)->for($this->user)->create(['label' => 'Pixel']);
    channeledAlert($this->user, $unlinked, AlertRule::factory()->dailySummary(), ['name' => 'Phone summary']);
    channeledAlert($this->user, $linked, AlertRule::factory()->dailySummary(), ['name' => 'Pixel summary']);

    $rows = attentionRows($this->user);

    expect(array_map(fn (array $row): string => "{$row['kind']}: {$row['subject']}", $rows))->toBe([
        'location_device_missing: Phone summary',
        'location_no_position: Pixel summary',
    ])->and($rows[0])->toMatchArray([
        'severity' => 'warning',
        'detail' => "can't follow Phone: no phone is sharing its location any more",
        'target' => ['type' => 'location', 'id' => $unlinked->id],
    ])->and($rows[1]['detail'])->toBe('is waiting for Pixel to report a position');
});

test('a linked app location with a position raises no row', function () {
    $token = $this->user->createToken('Pixel 9', ['app'])->accessToken;
    $linked = Location::factory()->mobileApp($token)->withFix(40.0, -105.0)->for($this->user)->create();
    channeledAlert($this->user, $linked, AlertRule::factory()->dailySummary());

    expect(attentionRows($this->user))->toBe([]);
});

test('a missing device on an inactive alert or a stationary location is not reported as a device problem', function () {
    $gone = Location::factory()->mobile()->for($this->user)->create(['traccar_device_missing_at' => now()]);
    AlertRule::factory()->dailySummary()->for($this->user)->for($gone)->create(['is_active' => false, 'name' => 'Paused']);

    expect(attentionSummary($this->user))->toBe(['alert_inactive: Paused']);
});

test('a mobile location with a position, a stationary location, or an inactive alert is not a position problem', function () {
    $withFix = Location::factory()->mobile()->withFix(40.0, -105.0)->for($this->user)->create();
    $noFix = Location::factory()->mobile()->for($this->user)->create();
    channeledAlert($this->user, $withFix, AlertRule::factory()->dailySummary());
    channeledAlert($this->user, Location::factory()->for($this->user)->create(), AlertRule::factory()->dailySummary());
    AlertRule::factory()->dailySummary()->for($this->user)->for($noFix)->create(['is_active' => false, 'name' => 'Paused']);

    expect(attentionSummary($this->user))->toBe(['alert_inactive: Paused']);
});

test('an inactive alert is reported once, as inactive', function () {
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Storm warnings']);

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['kind' => 'alert_inactive', 'severity' => 'gray', 'subject' => 'Storm warnings', 'detail' => 'is turned off'])
        ->and($rows[0]['target']['type'])->toBe('alert');
});

test('an active alert with no notification channel is reported as unable to deliver', function () {
    $alert = attentionAlert($this->user, [], ['name' => 'Storm warnings']);

    $rows = attentionRows($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'kind' => 'alert_no_channel',
            'severity' => 'danger',
            'subject' => 'Storm warnings',
            'detail' => "has no notification channel, so it can't deliver — add a channel to it",
            'target' => ['type' => 'alert', 'id' => $alert->id],
        ]);
});

test('an active alert with a channel is not reported as channel-less', function () {
    attentionAlert($this->user, [NotificationChannel::factory()->for($this->user)->create()]);

    expect(attentionRows($this->user))->toBe([]);
});

test('an inactive alert with no channel is reported only as inactive', function () {
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Paused']);

    expect(attentionSummary($this->user))->toBe(['alert_inactive: Paused']);
});

test('another subscriber\'s channel-less alert is not reported', function () {
    attentionAlert(User::factory()->create(), [], ['name' => 'Their alert']);

    expect(attentionRows($this->user))->toBe([]);
});

test('an alert that lost its only device channel is reported as channel-less', function () {
    $device = NotificationChannel::factory()->for($this->user)->create();
    $alert = attentionAlert($this->user, [$device], ['name' => 'Phone only']);
    expect(attentionRows($this->user))->toBe([]);

    $device->delete();

    expect(attentionSummary($this->user))->toBe(['alert_no_channel: Phone only'])
        ->and($alert->fresh()->notificationChannels)->toHaveCount(0);
});

test('rows are ordered failing, unverified, no channel, no position, inactive and then by name', function () {
    $failing = NotificationChannel::factory()->for($this->user)->create(['label' => 'Zed channel']);
    channelAttempt($this->user, $failing, 'failure', now()->subHour());
    $unverified = NotificationChannel::factory()->for($this->user)->create(['label' => 'Alpha email', 'verified_at' => null]);
    attentionAlert($this->user, [$unverified], ['name' => 'Uses alpha']);
    channeledAlert($this->user, Location::factory()->mobile()->for($this->user)->create(['label' => 'Truck']), AlertRule::factory()->dailySummary(), ['name' => 'Truck summary']);
    attentionAlert($this->user, [], ['name' => 'Zulu no channel']);
    attentionAlert($this->user, [], ['name' => 'Bravo no channel']);
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Beta off']);
    attentionAlert($this->user, [], ['is_active' => false, 'name' => 'Alpha off']);

    expect(attentionSummary($this->user))->toBe([
        'channel_failing: Zed channel',
        'channel_unverified: Alpha email',
        'alert_no_channel: Bravo no channel',
        'alert_no_channel: Zulu no channel',
        'location_no_position: Truck summary',
        'alert_inactive: Alpha off',
        'alert_inactive: Beta off',
    ]);
});

test('a healthy subscriber has no rows', function () {
    $channel = NotificationChannel::factory()->for($this->user)->create();
    attentionAlert($this->user, [$channel]);
    channelAttempt($this->user, $channel, 'success', now()->subHour());

    expect(attentionRows($this->user))->toBe([]);
});

test('another subscriber\'s problems never appear, and no row leaks a destination or error detail', function () {
    $stranger = User::factory()->create();
    $theirs = NotificationChannel::factory()->for($stranger)->create(['label' => 'Their channel', 'verified_at' => null]);
    attentionAlert($stranger, [$theirs], ['is_active' => false, 'name' => 'Their alert']);
    channelAttempt($stranger, $theirs, 'failure', now()->subHour());

    $mine = NotificationChannel::factory()->for($this->user)->create(['label' => 'Mine']);
    channelAttempt($this->user, $mine, 'failure', now()->subHour());

    $json = json_encode(attentionRows($this->user));

    expect(attentionSummary($this->user))->toBe(['channel_failing: Mine'])
        ->and($json)->not->toContain('Their')->not->toContain('secret-destination')->not->toContain('hooks.example.com');
});

test('the number of queries does not grow with the number of problems', function () {
    $count = function (int $problems): int {
        AlertRule::query()->where('user_id', $this->user->id)->delete();
        NotificationChannel::query()->where('user_id', $this->user->id)->delete();
        foreach (range(1, $problems) as $i) {
            $channel = NotificationChannel::factory()->for($this->user)->create(['verified_at' => null]);
            attentionAlert($this->user, [$channel], ['is_active' => $i % 2 === 0]);
            channelAttempt($this->user, NotificationChannel::factory()->for($this->user)->create(), 'failure', now()->subHour());
            AlertRule::factory()->dailySummary()->for($this->user)->for(Location::factory()->mobile()->for($this->user)->create())->create(['name' => null]);
            attentionAlert($this->user, [], ['name' => "No channel {$i}"]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        attentionRows($this->user);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    // Two is the smallest set that has both an active and an inactive alert.
    expect($count(8))->toBe($count(2));
});
