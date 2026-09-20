<?php

use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\DeliveryResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Spatie\Activitylog\Models\Activity;

test('an apprise channel is delivered through apprise and a 200 is a success', function () {
    Http::fake(['*/notify/*' => Http::response(status: 200)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord', 'apprise_url' => 'discord://1/abc/']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result)->toBeInstanceOf(DeliveryResult::class)
        ->and($result->success)->toBeTrue()->and($result->error)->toBeNull();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/notify/')
        && $request['urls'] === 'discord://1/abc/' && $request['body'] === 'body' && $request['title'] === 'title');
});

test('apprise reporting anything but 200 is a failure with the existing message', function () {
    Http::fake(['*/notify/*' => Http::response(status: 204)]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Apprise reported non-delivery.');
});

test('a connection exception from apprise still propagates so queued jobs can retry', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('apprise is down')]);
    $channel = NotificationChannel::factory()->create(['channel_type' => 'discord']);

    expect(fn () => app(ChannelDispatcher::class)->send($channel, 'body'))->toThrow(ConnectionException::class);
});

test('an unknown channel type is a failed result, not an exception', function () {
    $channel = NotificationChannel::factory()->create(['channel_type' => 'carrier-pigeon']);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('carrier-pigeon');
});

test('config is stored encrypted and is not written to the activity log', function () {
    $channel = NotificationChannel::factory()->create([
        'channel_type' => 'discord', 'apprise_url' => null, 'config' => ['secret' => 'shh-super-secret'],
    ]);

    expect($channel->fresh()->config)->toBe(['secret' => 'shh-super-secret'])
        ->and(DB::table('notification_channels')->where('id', $channel->id)->value('config'))->not->toContain('shh-super-secret');

    $channel->update(['label' => 'renamed']);
    expect(json_encode(Activity::all()->toArray()))->not->toContain('shh-super-secret');
});
