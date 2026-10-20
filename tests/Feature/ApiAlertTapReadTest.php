<?php

use App\Actions\Channels\SendTestNotification;
use App\Models\AlertRule;
use App\Models\DeliveryLog;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\AlertRef;
use App\Support\Delivery\ChannelDispatcher;
use Illuminate\Support\Facades\Http;

function tapReadBatchKey(): string
{
    return '0b9f6f5e-3c1d-4a2b-9e8f-1a2b3c4d5e6f';
}

beforeEach(function () {
    $this->freezeTime();

    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('iPhone', ['app'])->plainTextToken;
    $this->rule = AlertRule::factory()->for($this->user)->create();
});

afterEach(function () {
    if (isset($this->credentials)) {
        @unlink($this->credentials['path']);
    }
});

/**
 * @param  array<string, mixed>  $data
 */
function tapReadRequest($test, string $bearer, array $data)
{
    // The guard caches the first request's user for the rest of the test.
    app('auth')->forgetGuards();

    return $test->withToken($bearer)->postJson('/api/v1/alerts/read-ref', $data);
}

/**
 * The FCM data `url` of every push sent so far, in order.
 *
 * @return list<string>
 */
function tapReadSentTargets(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_contains($pair[0]->url(), 'messages:send'))
        ->map(fn (array $pair) => ((array) $pair[0]['message']['data'])['url'])
        ->values()->all();
}

function tapReadLink(string $page): string
{
    return rtrim(strtr(base64_encode($page), '+/', '-_'), '=');
}

test('a group reference round-trips for a product group, a product group without a rule and a batch', function () {
    expect(AlertRef::forGroup(12, 345, null))->toBe('p.12.345')
        ->and(AlertRef::parse('p.12.345'))->toBe(['alert_rule_id' => 12, 'ingested_product_id' => 345, 'batch_key' => null])
        ->and(AlertRef::forGroup(null, 345, tapReadBatchKey()))->toBe('p.0.345')
        ->and(AlertRef::parse('p.0.345'))->toBe(['alert_rule_id' => null, 'ingested_product_id' => 345, 'batch_key' => null])
        ->and(AlertRef::forGroup(12, null, tapReadBatchKey()))->toBe('b.'.tapReadBatchKey())
        ->and(AlertRef::parse('b.'.tapReadBatchKey()))->toBe(['alert_rule_id' => null, 'ingested_product_id' => null, 'batch_key' => tapReadBatchKey()]);
});

test('a legacy row with neither a product nor a batch key has no reference', function () {
    expect(AlertRef::forGroup(12, null, null))->toBeNull()
        ->and(AlertRef::forGroup(null, null, null))->toBeNull()
        ->and(AlertRef::forGroup(12, null, 'not-a-uuid'))->toBeNull();
});

test('anything but a well-formed reference parses to null', function (string $ref) {
    expect(AlertRef::parse($ref))->toBeNull();
})->with([
    'empty' => [''],
    'unknown kind' => ['x.1.2'],
    'no product' => ['p.1'],
    'product zero' => ['p.1.0'],
    'leading zero' => ['p.01.2'],
    'negative' => ['p.-1.2'],
    'a slash' => ['p.1/2.3'],
    'trailing newline' => ["p.1.2\n"],
    'too many digits' => ['p.1.'.str_repeat('9', 19)],
    'upper-case uuid' => ['b.'.strtoupper(tapReadBatchKey())],
    'short uuid' => ['b.0b9f6f5e-3c1d-4a2b-9e8f'],
    'extra segment' => ['p.1.2.3'],
    'sql' => ["p.1.2' or 1=1"],
]);

test('reading by reference marks the caller\'s product group and returns the count after the write', function () {
    $product = IngestedProduct::factory()->create();
    $tapped = DeliveryLog::factory()->for($this->user)->for($this->rule)->for($product)->pushed()->create();
    $samePush = DeliveryLog::factory()->for($this->user)->for($this->rule)->for($product)->pushed()->create();
    $other = DeliveryLog::factory()->for($this->user)->for($this->rule)->for(IngestedProduct::factory())->pushed()->create();
    $theirs = DeliveryLog::factory()->for(User::factory())->for($this->rule)->for($product)->pushed()->create();

    tapReadRequest($this, $this->token, ['ref' => "p.{$this->rule->id}.{$product->id}"])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 1]]);

    expect($tapped->fresh()->read_at)->not->toBeNull()
        ->and($samePush->fresh()->read_at)->not->toBeNull()
        ->and($other->fresh()->read_at)->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
});

test('reading by reference matches a product group without a rule only to rows without a rule', function () {
    $product = IngestedProduct::factory()->create();
    $noRule = DeliveryLog::factory()->for($this->user)->for($product)->pushed()->create(['alert_rule_id' => null]);
    $withRule = DeliveryLog::factory()->for($this->user)->for($this->rule)->for($product)->pushed()->create();

    tapReadRequest($this, $this->token, ['ref' => "p.0.{$product->id}"])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 1]]);

    expect($noRule->fresh()->read_at)->not->toBeNull()->and($withRule->fresh()->read_at)->toBeNull();
});

test('reading a batch by reference marks both phones\' rows and leaves other users\' rows', function () {
    $phones = DeliveryLog::factory()->count(2)->for($this->user)->for($this->rule)->pushed()
        ->create(['ingested_product_id' => null, 'batch_key' => tapReadBatchKey()]);
    $theirs = DeliveryLog::factory()->for(User::factory())->pushed()->create(['ingested_product_id' => null, 'batch_key' => tapReadBatchKey()]);

    tapReadRequest($this, $this->token, ['ref' => 'b.'.tapReadBatchKey()])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 0]]);

    expect($phones->map->fresh()->pluck('read_at')->filter()->count())->toBe(2)
        ->and($theirs->fresh()->read_at)->toBeNull();
});

test('reading by reference is idempotent and keeps the first read time', function () {
    $product = IngestedProduct::factory()->create();
    $row = DeliveryLog::factory()->for($this->user)->for($this->rule)->for($product)->pushed()->create();

    tapReadRequest($this, $this->token, ['ref' => "p.{$this->rule->id}.{$product->id}"])->assertOk();
    $readAt = $row->fresh()->read_at;
    $this->travel(5)->minutes();
    tapReadRequest($this, $this->token, ['ref' => "p.{$this->rule->id}.{$product->id}"])->assertOk();

    expect($row->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

test('a reference that matches nothing yet is a harmless no-op with the current count', function () {
    $unrelated = DeliveryLog::factory()->for($this->user)->for($this->rule)->for(IngestedProduct::factory())->pushed()->create();

    tapReadRequest($this, $this->token, ['ref' => 'p.999999.999999'])
        ->assertOk()
        ->assertExactJson(['data' => ['unread_count' => 1]]);

    expect($unrelated->fresh()->read_at)->toBeNull();
});

test('a malformed reference is refused with 422', function (array $body) {
    tapReadRequest($this, $this->token, $body)->assertUnprocessable()->assertJsonValidationErrors('ref');
})->with([
    'missing' => [[]],
    'garbage' => [['ref' => 'drop table']],
    'a number' => [['ref' => 42]],
    'too long' => [['ref' => 'p.1.'.str_repeat('1', 60)]],
]);

test('a beacon token cannot read by reference', function () {
    $beacon = $this->user->createToken('Beacon', ['location'])->plainTextToken;
    $product = IngestedProduct::factory()->create();
    $row = DeliveryLog::factory()->for($this->user)->for($this->rule)->for($product)->pushed()->create();

    tapReadRequest($this, $beacon, ['ref' => "p.{$this->rule->id}.{$product->id}"])->assertForbidden();

    expect($row->fresh()->read_at)->toBeNull();
});

test('registration stores tap_ref when the app declares it and keeps it on a refresh', function () {
    $register = fn (array $body) => tapReadRegister($this, $body);

    $register(['tap_ref' => true])->assertNoContent();
    $register(['token' => 'fcm-token-2', 'tap_ref' => true])->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->config)
        ->toBe(['fcm_token' => 'fcm-token-2', 'platform' => 'ios', 'tap_ref' => true]);
});

test('a registration without tap_ref (an older app build) stores none and clears an earlier one', function () {
    tapReadRegister($this, ['tap_ref' => true])->assertNoContent();
    tapReadRegister($this, [])->assertNoContent();

    expect(NotificationChannel::query()->where('channel_type', 'mobile_push')->sole()->config)
        ->toBe(['fcm_token' => 'fcm-token-1', 'platform' => 'ios']);
});

test('a non-boolean tap_ref is refused', function () {
    tapReadRegister($this, ['tap_ref' => 'yes please'])->assertUnprocessable()->assertJsonValidationErrors('tap_ref');
});

/**
 * @param  array<string, mixed>  $body
 */
function tapReadRegister($test, array $body)
{
    app('auth')->forgetGuards();

    return $test->withToken($test->token)->putJson('/api/v1/push-token', $body + ['token' => 'fcm-token-1', 'platform' => 'ios']);
}

test('a phone that supports tap references gets them in its tap links', function () {
    $this->credentials = fcmUseCredentials();
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('tap-ref-token', 'android')->create(['config' => ['fcm_token' => 'tap-ref-token', 'platform' => 'android', 'tap_ref' => true]]);
    $page = 'https://app.example.test/view-product/kbou-tor-202610121500';
    $dispatcher = app(ChannelDispatcher::class);

    $dispatcher->send($phone, 'Body', 'Tornado Warning', ['type' => 'alert', 'url' => $page, 'alert_rule_id' => 7, 'ingested_product_id' => 88]);
    $dispatcher->send($phone, 'Body', 'Tornado Warning', ['type' => 'alert', 'url' => $page, 'alert_rule_id' => null, 'ingested_product_id' => 88]);
    $dispatcher->send($phone, 'Body', 'Bulletin', ['type' => 'alert', 'url' => 'https://evil.example/x', 'alert_rule_id' => 7, 'ingested_product_id' => 88]);
    $dispatcher->send($phone, 'Body', 'Daily summary', ['type' => 'alert', 'alert_rule_id' => 7, 'ingested_product_id' => null, 'batch_key' => tapReadBatchKey()]);
    $dispatcher->send($phone, 'Body', 'Old job', ['type' => 'alert', 'alert_rule_id' => 7]);
    $dispatcher->send($phone, 'Body', 'Old job', ['type' => 'alert', 'url' => $page, 'alert_rule_id' => 7]);
    app(SendTestNotification::class)($phone);

    expect(tapReadSentTargets())->toBe([
        'wxnotify://product/'.tapReadLink($page).'/p.7.88',
        'wxnotify://product/'.tapReadLink($page).'/p.0.88',
        'wxnotify://alert/p.7.88',
        'wxnotify://alert/b.'.tapReadBatchKey(),
        '/',
        'wxnotify://product/'.tapReadLink($page),
        '/',
    ]);
});

test('a phone that has not declared tap references keeps today\'s exact tap links', function (array $config) {
    $this->credentials = fcmUseCredentials();
    fcmFakeGoogle();
    $phone = NotificationChannel::factory()->mobilePush('old-app-token', 'android')->create(['config' => ['fcm_token' => 'old-app-token', 'platform' => 'android', ...$config]]);
    $page = 'https://app.example.test/view-product/kbou-tor-202610121500';
    $dispatcher = app(ChannelDispatcher::class);

    $dispatcher->send($phone, 'Body', 'Tornado Warning', ['type' => 'alert', 'url' => $page, 'alert_rule_id' => 7, 'ingested_product_id' => 88]);
    $dispatcher->send($phone, 'Body', 'Daily summary', ['type' => 'alert', 'alert_rule_id' => 7, 'ingested_product_id' => null, 'batch_key' => tapReadBatchKey()]);

    expect(tapReadSentTargets())->toBe([
        'wxnotify://product/'.tapReadLink($page),
        '/',
    ]);
})->with([
    'no flag' => [[]],
    'flag false' => [['tap_ref' => false]],
    'flag not strictly true' => [['tap_ref' => 'true']],
]);
