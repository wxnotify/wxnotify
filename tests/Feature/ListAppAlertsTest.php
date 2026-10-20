<?php

use App\Actions\Alerts\ListAppAlerts;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\User;

test('an email-only alert is not in the feed', function () {
    $user = User::factory()->create();
    DeliveryLog::factory()->for($user)->create(['channel_type' => 'email', 'ingested_product_id' => IngestedProduct::factory()]);
    DeliveryLog::factory()->for($user)->create(['channel_type' => 'email', 'ingested_product_id' => null]);

    expect((new ListAppAlerts)($user)['alerts'])->toBeEmpty();
});

test('an alert with a successful push appears once even with email and Discord rows in the group', function () {
    $user = User::factory()->create();
    $rule = AlertRule::factory()->for($user)->create();
    $product = IngestedProduct::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for($rule)->for($product);

    $email = $group->create(['channel_type' => 'email', 'dispatched_at' => now()->subMinutes(3)]);
    $group->create(['channel_type' => 'discord', 'dispatched_at' => now()->subMinutes(2)]);
    $group->pushed()->create(['dispatched_at' => now()->subMinute()]);

    $alerts = (new ListAppAlerts)($user)['alerts'];

    expect($alerts)->toHaveCount(1)
        ->and($alerts->first()->id())->toBe($email->id);
});

test('a group whose only push failed is not in the feed', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $group->create(['channel_type' => 'email']);
    $group->pushed()->failed()->create();

    expect((new ListAppAlerts)($user)['alerts'])->toBeEmpty();
});

test('the same alert pushed to two phones is one feed entry', function () {
    $user = User::factory()->create();
    $group = DeliveryLog::factory()->for($user)->for(AlertRule::factory()->for($user))->for(IngestedProduct::factory());

    $group->pushed()->count(2)->create();

    expect((new ListAppAlerts)($user)['alerts'])->toHaveCount(1);
});

test('the cursor pages only over pushed alerts', function () {
    $user = User::factory()->create();
    $older = DeliveryLog::factory()->for($user)->pushed()->create(['ingested_product_id' => IngestedProduct::factory(), 'dispatched_at' => now()->subMinutes(3)]);
    DeliveryLog::factory()->for($user)->create(['channel_type' => 'email', 'ingested_product_id' => IngestedProduct::factory(), 'dispatched_at' => now()->subMinutes(2)]);
    $newer = DeliveryLog::factory()->for($user)->pushed()->create(['ingested_product_id' => IngestedProduct::factory(), 'dispatched_at' => now()->subMinute()]);

    $first = (new ListAppAlerts)($user, limit: 1);
    $second = (new ListAppAlerts)($user, limit: 1, before: $first['next_cursor']);

    expect($first['alerts']->first()->id())->toBe($newer->id)
        ->and($second['alerts']->first()->id())->toBe($older->id)
        ->and($second['next_cursor'])->toBeNull();
});
