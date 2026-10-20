<?php

use App\Actions\Alerts\PushBadgeCount;
use App\Actions\Channels\SendTestNotification;
use App\Jobs\PruneMobilePushChannel;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->credentials = fcmUseCredentials();
    $this->channel = NotificationChannel::factory()->mobilePush('device-token-1')->create();
});

afterEach(function () {
    @unlink($this->credentials['path']);
});

/**
 * The `aps.badge` of every FCM send recorded so far, in order.
 *
 * @return list<int|null>
 */
function mobilePushSentBadges(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'messages:send'))
        ->map(fn (array $pair) => $pair[0]['message']['apns']['payload']['aps']['badge'] ?? null)
        ->values()->all();
}

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

test('an iOS push carries the unread alert count including the alert it pushes', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('ios-token-1', 'ios')->create();
    $rule = AlertRule::factory()->for($phone->user)->create();
    DeliveryLog::factory()->for($phone->user)->for($rule)->for(IngestedProduct::factory())->pushed()->create();
    $product = IngestedProduct::factory()->create();

    app(ChannelDispatcher::class)->send($phone, 'Body', 'Title', ['type' => 'alert', 'alert_rule_id' => $rule->id, 'ingested_product_id' => $product->id]);

    expect(mobilePushSentBadges())->toBe([2]);
});

test('an iOS push for an alert that is already unread does not count it twice', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('ios-token-1', 'ios')->create();
    $rule = AlertRule::factory()->for($phone->user)->create();
    $product = IngestedProduct::factory()->create();
    DeliveryLog::factory()->for($phone->user)->for($rule)->for($product)->pushed()->create();
    DeliveryLog::factory()->for($phone->user)->for($rule)->for(IngestedProduct::factory())->pushed()->create();

    app(ChannelDispatcher::class)->send($phone, 'Body', 'Title', ['type' => 'alert', 'alert_rule_id' => $rule->id, 'ingested_product_id' => $product->id]);

    expect(mobilePushSentBadges())->toBe([2]);
});

test('the test push carries the plain unread count', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('ios-token-1', 'ios')->create();
    DeliveryLog::factory()->for($phone->user)->for(IngestedProduct::factory())->pushed()->create();
    DeliveryLog::factory()->for($phone->user)->for(IngestedProduct::factory())->pushed()->create();

    app(SendTestNotification::class)($phone);

    expect(mobilePushSentBadges())->toBe([2]);
});

test('a failing badge count still sends the iOS push, without a badge', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('ios-token-badge-down', 'ios')->create();
    app()->instance(PushBadgeCount::class, new class extends PushBadgeCount
    {
        public function __invoke(User $user, ?int $alertRuleId, ?int $ingestedProductId, bool $countsAsAlert, ?string $batchKey = null): int
        {
            throw new RuntimeException('column read_at does not exist');
        }
    });
    Log::spy();

    $result = app(ChannelDispatcher::class)->send($phone, 'Body', 'Title');

    expect($result->success)->toBeTrue();
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && ! array_key_exists('badge', $request['message']['apns']['payload']['aps']));
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => $message === 'Push badge count failed: RuntimeException');
});

test('an Android push has no badge', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('android-token-1', 'android')->create();

    app(ChannelDispatcher::class)->send($phone, 'Body', 'Title');

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'messages:send')
        && ! array_key_exists('badge', $request['message']['apns']['payload']['aps']));
});

test('an iOS device with no stored token fails without calling Firebase', function () {
    Http::fake();
    $phone = NotificationChannel::factory()->mobilePush()->create(['config' => ['platform' => 'ios']]);

    $result = app(ChannelDispatcher::class)->send($phone, 'Body', 'Title');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('This device channel has no push token; open the app to register it again.');
    Http::assertNothingSent();
});

test('the badge is never a data field', function () {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('ios-token-3', 'ios')->create();

    app(ChannelDispatcher::class)->send($phone, 'Body', 'Title');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'messages:send')) {
            return false;
        }
        $data = (array) $request['message']['data'];

        return ! array_key_exists('badge', $data) && collect($data)->every(fn ($value) => is_string($value));
    });
});

test('every phone push still names the old builds\' foreground-push event with an empty JSON payload, as strings', function (string $platform) {
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush("{$platform}-event-token", $platform)->create();

    app(ChannelDispatcher::class)->send($phone, 'Body', 'Title');

    Http::assertSent(function (Request $request) {
        if (! str_contains($request->url(), 'messages:send')) {
            return false;
        }
        $data = (array) $request['message']['data'];

        return $data['event'] === 'App\\Events\\ForegroundPushReceived'
            && $data['payload'] === '{}'
            && $data['url'] === '/'
            && $data['type'] === 'alert'
            && ! array_key_exists('content_available', $request['message']['apns']['payload']['aps'])
            && ! array_key_exists('content-available', $request['message']['apns']['payload']['aps']);
    });
})->with(['ios', 'android']);

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
