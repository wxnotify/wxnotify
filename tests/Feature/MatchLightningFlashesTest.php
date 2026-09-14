<?php

use App\Actions\Weather\MatchLightningFlashes;
use App\Jobs\DeliverScheduledAlert;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake();
});

test('a flash within a rule\'s radius (plus the sensor buffer) dispatches an alert', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    // ~0.1 degrees latitude is roughly 7 miles — well within a 15mi radius
    // plus the ~8.7mi sensor buffer.
    (new MatchLightningFlashes)([['id' => '1', 'lat' => 39.1, 'lon' => -95.0]]);

    Bus::assertDispatched(DeliverScheduledAlert::class, fn ($job) => $job->ruleId === $rule->id && $job->channelId === $channel->id);
    expect($rule->fresh()->lightning_last_alerted_at)->not->toBeNull();
});

test('a flash far outside the radius does not dispatch', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 45.0, 'lon' => -95.0]]);

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});

test('a rule still within its cooldown is not re-alerted', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create([
        'radius_miles' => 15,
        'lightning_last_alerted_at' => now()->subMinutes(10),
    ]);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 39.1, 'lon' => -95.0]]);

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});

test('a rule whose cooldown has expired can be re-alerted', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create([
        'radius_miles' => 15,
        'lightning_last_alerted_at' => now()->subMinutes(31),
    ]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 39.1, 'lon' => -95.0]]);

    Bus::assertDispatched(DeliverScheduledAlert::class);
});

test('an inactive rule is never matched', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15, 'is_active' => false]);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 39.1, 'lon' => -95.0]]);

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});

test('a flash near a fixless mobile location never matches (raw SQL, not a PHP guard)', function () {
    Bus::fake();
    $user = User::factory()->create();
    $location = Location::factory()->mobile()->for($user)->create();
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    (new MatchLightningFlashes)([['id' => '1', 'lat' => 0.0, 'lon' => 0.0]]);

    Bus::assertNotDispatched(DeliverScheduledAlert::class);
});

test('multiple flashes in one batch only alert a matched rule once, respecting its own cooldown', function () {
    $user = User::factory()->create();
    $location = Location::factory()->for($user)->create(['lat' => 39.0, 'lng' => -95.0]);
    $rule = AlertRule::factory()->lightning()->for($user)->for($location)->create(['radius_miles' => 15]);
    $channel = NotificationChannel::factory()->for($user)->create();
    $rule->notificationChannels()->attach($channel);

    (new MatchLightningFlashes)([
        ['id' => '1', 'lat' => 39.1, 'lon' => -95.0],
        ['id' => '2', 'lat' => 39.05, 'lon' => -95.0],
        ['id' => '3', 'lat' => 38.95, 'lon' => -95.0],
    ]);

    Bus::assertDispatchedTimes(DeliverScheduledAlert::class, 1);
});
