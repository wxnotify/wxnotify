<?php

use App\Actions\Channels\SendTestNotification;
use App\Jobs\PruneMobilePushChannel;
use App\Models\DeliveryLog;
use App\Models\NotificationChannel;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    $this->channel = NotificationChannel::factory()->mobilePush('device-token-1')->create();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

test('an alert is pushed to the device with its subject as the title and the feed as the tap target', function () {
    fcmFakeGoogle();

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Slight risk of severe storms this afternoon.', 'SPC Day 1 Convective Outlook');

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['token'] === 'device-token-1'
        && $request['message']['notification']['title'] === 'SPC Day 1 Convective Outlook'
        && $request['message']['notification']['body'] === 'Slight risk of severe storms this afternoon.'
        && ((array) $request['message']['data'])['url'] === '/');
});

test('an alert about a stored bulletin carries an app link to its product page, as one base64url segment', function () {
    fcmFakeGoogle();
    $page = 'https://app.example.test/view-product/kbou-tor-202610121500';
    $encoded = rtrim(strtr(base64_encode($page), '+/', '-_'), '=');

    app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Tornado Warning', ['type' => 'alert', 'url' => $page]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && ((array) $request['message']['data'])['url'] === "wxnotify://product/{$encoded}"
        && preg_match('/^[A-Za-z0-9_-]+$/', $encoded) === 1);
});

test('only a public View Product page address becomes an app link; anything else opens the feed', function (?string $url) {
    fcmFakeGoogle();

    app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title', ['url' => $url]);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && ((array) $request['message']['data'])['url'] === '/');
})->with([
    'no address' => [null],
    'another site' => ['https://evil.example/login'],
    'not a product page' => ['https://app.example.test/dashboard'],
    'plain http' => ['http://app.example.test/view-product/abc'],
    'a query string' => ['https://app.example.test/view-product/abc?x=1'],
    'an upper-case slug' => ['https://app.example.test/view-product/ABC'],
]);

test('a long message is cut to about 200 characters', function () {
    fcmFakeGoogle();

    app(ChannelDispatcher::class)->send($this->channel, str_repeat('word ', 100), 'Title');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && mb_strlen($request['message']['notification']['body']) <= 203);
});

test('a missing title falls back to the product name', function () {
    fcmFakeGoogle();

    app(ChannelDispatcher::class)->send($this->channel, 'Body', null);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['notification']['title'] === 'WxNotify');
});

test('a device with no stored token fails without calling Firebase', function () {
    Http::fake();
    $channel = NotificationChannel::factory()->mobilePush()->create(['config' => ['platform' => 'android']]);

    $result = app(ChannelDispatcher::class)->send($channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This device channel has no push token; open the app to register it again.');
    Http::assertNothingSent();
});

test('an unconfigured server fails with the fixed message', function () {
    config(['services.firebase.service_account' => null]);
    Http::fake();

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Push is not configured on this server.');
});

test('a token Firebase no longer knows fails and queues a delayed prune of the channel', function () {
    Queue::fake();
    fcmFakeGoogle(Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404));

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->success)->toBeFalse()
        ->and($result->error)->toBe('This device is no longer registered for push.')
        ->and($result->error)->not->toContain('device-token-1');
    Queue::assertPushed(PruneMobilePushChannel::class, fn (PruneMobilePushChannel $job) => $job->channelId === $this->channel->id
        && $job->tokenHash === sha1('device-token-1')
        && $job->delay === 60);
    expect($this->channel->fresh())->not->toBeNull();
});

test('other Firebase failures neither queue a prune nor leak anything', function () {
    Queue::fake();
    fcmFakeGoogle(Http::response(['error' => ['message' => 'secret detail device-token-1']], 503));

    $result = app(ChannelDispatcher::class)->send($this->channel, 'Body', 'Title');

    expect($result->error)->toBe('Firebase rejected the message (HTTP 503).');
    Queue::assertNothingPushed();
});

test('the prune job deletes the channel that still holds the failed token and keeps the delivery log', function () {
    $log = DeliveryLog::create([
        'user_id' => $this->channel->user_id,
        'notification_channel_id' => $this->channel->id,
        'channel_type' => 'mobile_push',
        'status' => 'failure',
        'dispatched_at' => now(),
    ]);

    (new PruneMobilePushChannel($this->channel->id, sha1('device-token-1')))->handle();

    expect(NotificationChannel::find($this->channel->id))->toBeNull()
        ->and($log->fresh())->not->toBeNull()
        ->and($log->fresh()->notification_channel_id)->toBeNull();
});

test('the prune job spares a channel whose phone registered a new token meanwhile', function () {
    $this->channel->forceFill(['config' => ['fcm_token' => 'device-token-2', 'platform' => 'android']])->save();

    (new PruneMobilePushChannel($this->channel->id, sha1('device-token-1')))->handle();

    expect(NotificationChannel::find($this->channel->id))->not->toBeNull();
});

test('the prune job ignores a channel of another type and one that is already gone', function () {
    $discord = NotificationChannel::factory()->create();

    (new PruneMobilePushChannel($discord->id, sha1('x')))->handle();
    (new PruneMobilePushChannel(999999, sha1('x')))->handle();

    expect(NotificationChannel::find($discord->id))->not->toBeNull();
});

test('the Send Test action reaches a device channel', function () {
    fcmFakeGoogle();

    expect(app(SendTestNotification::class)($this->channel))->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && $request['message']['notification']['title'] === 'WxNotify test notification');
});
