<?php

use App\Enums\ChannelTransport;
use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\NoRedirectCallWebhookJob;
use App\Support\Delivery\WebhookUrlGuard;
use Database\Seeders\ChannelTypeSeeder;
use Filament\Forms\Components\Field;
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
    chanFakePauser();
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
    $field = collect((new WebhookChannelType)->formSchema())
        ->firstWhere(fn ($f) => $f instanceof Field && $f->getName() === 'url');

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
        ->and($payload)->toMatchArray(['type' => 'alert', 'title' => 'Tornado Warning', 'body' => 'Tornado Warning text'])
        // Alert details live under a nested "alert" object, not at the top level.
        ->and($payload['alert'])->toBe(['rule' => 'My rule', 'product' => 'Tornado Warning'])
        ->and($payload)->not->toHaveKey('rule')
        ->and($payload)->not->toHaveKey('product')
        ->and($payload['sent_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/')
        ->and($request->getHeaderLine('Signature'))->toBe(hash_hmac('sha256', $body, 's3cret'));
});

test('caller meta can never overwrite the reserved top-level payload keys', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    app(ChannelDispatcher::class)->send($channel, 'real body', 'real title', [
        'type' => 'alert',
        'title' => 'spoofed',
        'body' => 'spoofed',
        'sent_at' => 'spoofed',
        'rule' => 'My rule',
    ]);

    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    expect($payload['title'])->toBe('real title')
        ->and($payload['body'])->toBe('real body')
        ->and($payload['sent_at'])->not->toBe('spoofed')
        ->and($payload['alert'])->toBe(['rule' => 'My rule']);
});

test('the alert group ids and push sound meant for the phone never reach a webhook', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    app(ChannelDispatcher::class)->send($channel, 'body', 'title', [
        'type' => 'alert',
        'rule' => 'My rule',
        'alert_rule_id' => 12,
        'ingested_product_id' => 34,
        'batch_key' => '0b9c1f9e-2f6d-4a39-9a51-3d7c2f1e8a10',
        'push_sound' => 'alert',
    ]);

    $body = (string) $history[0]['request']->getBody();

    expect(json_decode($body, true)['alert'])->toBe(['rule' => 'My rule'])
        ->and($body)->not->toContain('push_sound');
});

test('a confirmation message carries the code in the payload', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    app(ChannelDispatcher::class)->send($channel, 'Your code', 'Confirm', ['type' => 'confirmation', 'code' => '123456']);

    $payload = json_decode((string) $history[0]['request']->getBody(), true);

    // A confirmation keeps "code" at the top level and carries no "alert" object.
    expect($payload)->toMatchArray(['type' => 'confirmation', 'code' => '123456'])
        ->and($payload)->not->toHaveKey('alert');
});

// Spatie is deliberately given maximumTries(1) on every call — with dispatchSync()
// a failing multi-try call releases itself back onto the queue and looks successful
// to the caller. Retrying is this app's own loop instead (WebhookChannelOptionsTest
// covers the retry semantics); here we only pin that the default of 3 tries is what
// gets spent, and that the final failure is reported with its HTTP status.
test('a non-2xx response is a failure with a useful message after the default three attempts', function () {
    $history = chanFakeWebhookEndpoint([new Response(500), new Response(500), new Response(500), new Response(200)]);
    $channel = NotificationChannel::factory()->webhook()->create();

    $result = app(ChannelDispatcher::class)->send($channel, 'body', 'title');

    expect($result->success)->toBeFalse()->and($result->error)->toContain('500')
        ->and(count($history))->toBe(3);
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

// Pins the load-bearing half of the test above: the redirect can only be refused
// because Spatie is configured to run this app's NoRedirectCallWebhookJob. A
// `vendor:publish --force` or a bad merge that restored Spatie's own
// CallWebhookJob would silently reopen the redirect-SSRF hole, and the redirect
// test would keep passing on a mock handler that never redirects for real.
test('the webhook job is pinned to the no-redirect subclass', function () {
    expect(config('webhook-server.webhook_job'))->toBe(NoRedirectCallWebhookJob::class);
});

test('a connection error is a failure, not an exception', function () {
    $request = new Request('POST', 'https://hooks.example.com/wx');
    chanFakeWebhookEndpoint(array_fill(0, 3, new ConnectException('boom', $request)));
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

test('neither the signing secret nor the endpoint path or query reaches the activity log or the error message', function () {
    chanFakeWebhookEndpoint(array_fill(0, 3, new Response(500, [], 'echoing s3cret')));
    $url = 'https://hooks.example.com/wx?token=abc123';
    // The real auto-generated label, not the factory's fake()->word(): the label
    // is activity-logged, so anything the label embeds is in the log by design.
    // Option (a) of the review: the derived label deliberately carries the
    // endpoint HOST (a subscriber needs to tell their channels apart), so this
    // asserts the secret and the URL's path/query -- the parts that can carry a
    // credential -- stay out, rather than the host.
    $label = (new WebhookChannelType)->buildDestination(['url' => $url])['label'];
    $channel = NotificationChannel::factory()->webhook($url, 's3cret')->create(['label' => $label]);

    $result = app(ChannelDispatcher::class)->send($channel, 'body');
    $activity = json_encode(Activity::all()->toArray());

    expect($label)->toBe('Webhook (hooks.example.com)')
        ->and($result->error)->not->toContain('s3cret')
        ->and($result->error)->not->toContain('hooks.example.com')
        ->and($result->error)->not->toContain('token=abc123')
        ->and($result->error)->not->toContain('abc123')
        ->and($activity)->not->toContain('s3cret')
        ->and($activity)->not->toContain('/wx')
        ->and($activity)->not->toContain('token=abc123')
        ->and($activity)->not->toContain('abc123');
});

test('the request is pinned to the addresses the guard vetted', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(['url' => 'https://hooks.example.com:8443/wx']), 'body', 'title');

    expect($result->success)->toBeTrue()
        ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['hooks.example.com:8443:93.184.216.34'])
        ->and($history[0]['options']['allow_redirects'])->toBeFalse();
});

test('behind a proxy the proxy host is pinned, since the proxy resolves the target', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn (string $host): array => $host === 'proxy.example.com' ? ['203.0.113.7'] : ['93.184.216.34']));

    $channel = chanWebhookChannel(['use_proxy' => true, 'proxy_url' => 'http://proxy.example.com:3128']);

    expect(app(ChannelDispatcher::class)->send($channel, 'body', 'title')->success)->toBeTrue()
        ->and($history[0]['options']['curl'][CURLOPT_RESOLVE])->toBe(['proxy.example.com:3128:203.0.113.7']);
});

test('a host that turns private between the send-time check and the connection is not called', function () {
    $history = chanFakeWebhookEndpoint([new Response(200)]);
    $answers = [['93.184.216.34'], ['10.0.0.5']];
    app()->instance(WebhookUrlGuard::class, new WebhookUrlGuard(function (string $host) use (&$answers): array {
        return array_shift($answers) ?? ['10.0.0.5'];
    }));

    $result = app(ChannelDispatcher::class)->send(chanWebhookChannel(), 'body', 'title');

    expect($result->success)->toBeFalse()
        ->and($history)->toHaveCount(0);
});
