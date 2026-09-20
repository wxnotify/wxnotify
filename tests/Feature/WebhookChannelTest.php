<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\WebhookUrlGuard;
use Database\Seeders\ChannelTypeSeeder;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Models\Activity;

// chanFakeWebhookEndpoint() lives in tests/Pest.php.

beforeEach(function () {
    // Every hostname resolves to a public address unless a test says otherwise.
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['93.184.216.34']));
});

test('the webhook type is a free-slot, non-inline, webhook-transport type', function () {
    $type = app(ChannelTypeRegistry::class)->find('webhook');

    expect($type)->toBeInstanceOf(WebhookChannelType::class)
        ->and($type->transport())->toBe(ChannelTransport::Webhook)
        ->and($type->countsTowardFreeSlot())->toBeTrue()
        ->and($type->supportsInlineCreate())->toBeFalse();
});

test('the catalog ships webhook at sort order 8 and the picker offers it', function () {
    expect(DB::table('channel_types')->where('slug', 'webhook')->value('sort_order'))->toBe(8)
        ->and(array_column(ChannelTypeSeeder::SHIPPED, 'slug'))->toContain('webhook')
        ->and(app(ChannelTypeRegistry::class)->forPicker()->map(fn (array $row) => $row['type']->slug())->all())
        ->toContain('webhook');
});

test('building the destination generates a 64-character secret and keeps the url', function () {
    $destination = (new WebhookChannelType)->buildDestination(['url' => 'https://hooks.example.com/wx']);

    expect($destination['config']['url'])->toBe('https://hooks.example.com/wx')
        ->and($destination['config']['secret'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($destination)->not->toHaveKey('apprise_url')
        ->and($destination['label'])->toBe('Webhook (hooks.example.com)');
});

test('the url field rejects http and private targets', function () {
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));
    $field = collect((new WebhookChannelType)->formSchema())->firstWhere(fn ($f) => $f->getName() === 'url');

    foreach (['http://hooks.example.com/wx', 'https://hooks.example.com/wx', 'https://10.0.0.5/wx'] as $url) {
        expect(Validator::make(['url' => $url], ['url' => $field->getValidationRules()])->fails())->toBeTrue();
    }
});

test('delivery posts a signed json payload and reports success', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.com/wx', 's3cret')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'Tornado Warning text', 'Tornado Warning', ['type' => 'alert', 'rule' => 'My rule', 'product' => 'Tornado Warning']);

    expect($result->success)->toBeTrue();
    $request = $history[0]['request'];
    $body = (string) $request->getBody();
    $payload = json_decode($body, true);

    expect((string) $request->getUri())->toBe('https://hooks.example.com/wx')
        ->and($payload)->toMatchArray(['type' => 'alert', 'title' => 'Tornado Warning', 'body' => 'Tornado Warning text', 'rule' => 'My rule', 'product' => 'Tornado Warning'])
        ->and($payload['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($request->getHeaderLine('Signature'))->toBe(hash_hmac('sha256', $body, 's3cret'));
});

test('a confirmation message carries the code in the payload', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    app(ChannelDispatcher::class)->send($channel, 'Your code', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    expect(json_decode((string) $history[0]['request']->getBody(), true))->toMatchArray(['type' => 'confirmation', 'code' => '123456']);
});

test('a non-2xx response is a failure with a useful message and only one attempt is made', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('500')
        ->and(count($history))->toBe(1);
});

test('a redirect is never followed, so a public endpoint cannot bounce the request at a private address', function () {
    $history = chanFakeWebhookEndpoint([
        new Response(302, ['Location' => 'http://10.0.0.5/latest/meta-data/']),
        new Response(200, [], 'internal-metadata-contents'),
    ]);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.com/wx', 's3cret')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect(count($history))->toBe(1)
        ->and((string) $history[0]['request']->getUri())->toBe('https://hooks.example.com/wx')
        ->and($result->success)->toBeFalse()
        ->and($result->error)->not->toContain('internal-metadata-contents')
        ->and($result->error)->not->toContain('10.0.0.5');
});

test('a connection error is a failure, not an exception', function () {
    chanFakeWebhookEndpoint([new ConnectException('boom', new Request('POST', 'https://hooks.example.com/wx'))]);
    $channel = NotificationChannel::factory()->webhook()->create();

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('the url is re-checked at send time and a now-private target is never called', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn () => ['10.0.0.5']));

    $result = app(ChannelDispatcher::class)->send($channel, 'body');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('private')
        ->and(count($history))->toBe(0);
});

test('a webhook channel with a missing config is a failure', function () {
    $channel = NotificationChannel::factory()->webhook()->create(['config' => null]);

    expect(app(ChannelDispatcher::class)->send($channel, 'body')->success)->toBeFalse();
});

test('neither the signing secret nor the endpoint url reaches the activity log or the error message', function () {
    chanFakeWebhookEndpoint([new Response(500, [], 'echoing s3cret')]);
    $channel = NotificationChannel::factory()->webhook('https://hooks.example.com/wx?token=abc123', 's3cret')->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body');
    $activity = json_encode(Activity::all()->toArray());

    expect($result->error)->not->toContain('s3cret')
        ->and($result->error)->not->toContain('hooks.example.com')
        ->and($result->error)->not->toContain('token=abc123')
        ->and($result->error)->not->toContain('abc123')
        ->and($activity)->not->toContain('s3cret')
        ->and($activity)->not->toContain('hooks.example.com')
        ->and($activity)->not->toContain('abc123');
});
