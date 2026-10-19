<?php

use App\Models\NotificationChannel;
use Spatie\Activitylog\Models\Activity;

test('incrementUnreadPushCount returns 1, 2, 3 on successive calls', function () {
    $channel = NotificationChannel::factory()->mobilePush()->create();

    expect($channel->incrementUnreadPushCount())->toBe(1)
        ->and($channel->incrementUnreadPushCount())->toBe(2)
        ->and($channel->incrementUnreadPushCount())->toBe(3)
        ->and($channel->fresh()->unread_push_count)->toBe(3);
});

test('two channels count independently', function () {
    $first = NotificationChannel::factory()->mobilePush()->create();
    $second = NotificationChannel::factory()->mobilePush()->create();

    $first->incrementUnreadPushCount();
    $first->incrementUnreadPushCount();

    expect($second->incrementUnreadPushCount())->toBe(1)
        ->and($first->fresh()->unread_push_count)->toBe(2);
});

test('resetUnreadPushCount sets it to 0', function () {
    $channel = NotificationChannel::factory()->mobilePush()->create();
    $channel->incrementUnreadPushCount();

    $channel->resetUnreadPushCount();

    expect($channel->fresh()->unread_push_count)->toBe(0);
});

test('a counter change writes no activity-log row', function () {
    $channel = NotificationChannel::factory()->mobilePush()->create();
    $before = Activity::query()->count();

    $channel->incrementUnreadPushCount();
    $channel->resetUnreadPushCount();

    expect(Activity::query()->count())->toBe($before);
});

test('incrementUnreadPushCount returns null for a channel deleted after it was loaded', function () {
    $channel = NotificationChannel::factory()->mobilePush()->create();
    NotificationChannel::query()->whereKey($channel->id)->delete();

    expect($channel->incrementUnreadPushCount())->toBeNull();
});
