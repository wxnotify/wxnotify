<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebPushChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\WebPush;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    chanConfigureVapid();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
});

test('the web push type is free-slot, non-inline, webpush-transport, and only available with vapid keys', function () {
    $type = app(ChannelTypeRegistry::class)->find('webpush');

    expect($type)->toBeInstanceOf(WebPushChannelType::class)
        ->and($type->transport())->toBe(ChannelTransport::WebPush)
        ->and($type->countsTowardFreeSlot())->toBeTrue()
        ->and($type->supportsInlineCreate())->toBeFalse()
        ->and($type->isAvailable())->toBeTrue();

    config(['webpush.vapid.public_key' => null]);
    expect($type->isAvailable())->toBeFalse()
        ->and(app(ChannelTypeRegistry::class)->forPicker()->map(fn ($r) => $r['type']->slug())->all())->not->toContain('webpush');
});

test('a browser subscription is parsed into channel config', function () {
    expect(WebPushChannelType::parseSubscription(chanPushSubscriptionJson()))->toBe([
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        'public_key' => str_repeat('B', 87),
        'auth_token' => str_repeat('a', 22),
        'content_encoding' => 'aes128gcm',
    ]);
});

test('malformed subscriptions and unsafe endpoints are rejected', function (string $json) {
    expect(WebPushChannelType::parseSubscription($json))->toBeNull();
})->with([
    'not json' => 'nope',
    'json scalar' => '"x"',
    'no keys' => '{"endpoint":"https://fcm.googleapis.com/x"}',
    'http endpoint' => '{"endpoint":"http://fcm.googleapis.com/x","keys":{"p256dh":"a","auth":"b"}}',
    'empty' => '',
]);

test('an endpoint resolving to a private network is rejected at save time', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    expect(WebPushChannelType::parseSubscription(chanPushSubscriptionJson('https://push.evil.example/x')))->toBeNull();
});

test('building the destination labels the channel from the browser', function () {
    request()->headers->set('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36');

    $destination = (new WebPushChannelType)->buildDestination(['subscription' => chanPushSubscriptionJson()]);

    expect($destination['label'])->toBe('Web Push (Chrome on macOS)')->and($destination['config']['endpoint'])->toContain('fcm.googleapis.com');
});

test('delivery sends a json payload to the stored subscription', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning text', 'Tornado Warning', ['type' => 'alert']);

    expect($result->success)->toBeTrue()->and(count($sent))->toBe(1);
    $payload = json_decode($sent[0]['payload'], true);
    expect($payload)->toMatchArray(['title' => 'Tornado Warning', 'body' => 'Tornado Warning text', 'type' => 'alert'])
        ->and($sent[0]['subscription']->getEndpoint())->toBe($channel->config['endpoint']);
});

test('long bodies are truncated to fit a push payload', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    app(ChannelDispatcher::class)->send($channel, str_repeat('word ', 400), 'T');

    expect(strlen(json_decode($sent[0]['payload'], true)['body']))->toBeLessThanOrEqual(500);
});

test('a confirmation push carries the code in the body', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();

    app(ChannelDispatcher::class)->send($channel, 'Your WxNotify confirmation code is: 123456', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect(json_decode($sent[0]['payload'], true)['body'])->toContain('123456');
});

test('an expired subscription (410) is a failure and un-verifies the channel', function () {
    chanFakePushService(expired: true);
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('no longer')
        ->and($channel->fresh()->isVerified())->toBeFalse();
});

test('a push service error reports only the status and never the endpoint, keys or body', function () {
    chanFakePushService(success: false, status: 403);
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toBe('Push service rejected the message (HTTP 403).');
    foreach (['fcm.googleapis.com', 'abc123', str_repeat('B', 87), str_repeat('a', 22), 'body '] as $secret) {
        expect($result->error)->not->toContain($secret)
            ->and(Activity::all()->toJson())->not->toContain($secret);
    }
});

test('an expired subscription failure does not leak the endpoint either', function () {
    chanFakePushService(expired: true);
    $channel = NotificationChannel::factory()->webPush()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->error)->not->toContain('fcm.googleapis.com')->and($result->error)->not->toContain(str_repeat('B', 87));
});

test('the endpoint is re-checked at send time', function () {
    $sent = chanFakePushService();
    $channel = NotificationChannel::factory()->webPush()->create();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse()->and(count($sent))->toBe(0);
});

test('a missing vapid configuration fails delivery cleanly', function () {
    config(['webpush.vapid.private_key' => null]);
    $channel = NotificationChannel::factory()->webPush()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('redirects from the push service are not followed and count as a failure', function () {
    $history = new ArrayObject;
    $clientOptions = [];
    $mock = new MockHandler([
        new Response(302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
        new Response(201),
    ]);
    // Seam: WebPush is resolved through the container with the transport's Guzzle client as the
    // `client` parameter; this binding records that client's options, then builds a real WebPush
    // over a mocked handler stack (with Guzzle's redirect middleware) using them.
    app()->bind(WebPush::class, function ($app, array $params) use ($history, $mock, &$clientOptions) {
        $options = $clientOptions = $params['client']->getConfig();
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        return new WebPush($params['auth'], [], new Client(['handler' => $stack] + $options));
    });
    $channel = NotificationChannel::factory()->create(['channel_type' => 'webpush', 'apprise_url' => null, 'config' => chanRealPushSubscriptionConfig('https://push.example.com/send/xyz')]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    // Guzzle's PSR-18 sendRequest() also disables redirects itself; the client option is defence in depth.
    expect($clientOptions['allow_redirects'])->toBeFalse()
        ->and($result->success)->toBeFalse()
        ->and($result->error)->toBe('Push service rejected the message (HTTP 302).')
        ->and(count($history))->toBe(1)
        ->and($mock->count())->toBe(1);
});

test('the service worker handles push and notification click events', function () {
    $js = file_get_contents(public_path('dashboard-sw.js'));

    expect($js)->toContain("addEventListener('push'")
        ->and($js)->toContain("addEventListener('notificationclick'")
        ->and($js)->toContain('showNotification')
        ->and($js)->toContain('openWindow');
});

test('the setup view embeds the vapid public key, the service worker path, and never the private key', function () {
    config(['webpush.vapid.public_key' => 'PUBLICKEY123', 'webpush.vapid.private_key' => 'PRIVATEKEY456']);

    $html = view('filament.dashboard.channels.web-push-setup')->render();

    expect($html)->toContain('PUBLICKEY123')->toContain('/dashboard-sw.js')->not->toContain('PRIVATEKEY456');
});

test('the service worker only follows same-origin notification urls', function () {
    $js = file_get_contents(public_path('dashboard-sw.js'));

    expect($js)->toContain('self.location.origin')->toContain("url = '/dashboard'");
});

test('the setup view replaces a stale subscription and guards a missing public key', function () {
    config(['webpush.vapid.public_key' => 'PUBLICKEY123']);

    $html = view('filament.dashboard.channels.web-push-setup')->render();

    expect($html)->toContain('applicationServerKey')
        ->toContain('unsubscribe()')
        ->toContain('configured on this server yet');
});
