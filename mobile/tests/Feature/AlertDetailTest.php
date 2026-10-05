<?php

use App\NativeComponents\AlertDetail;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function detailPayload(array $overrides = []): array
{
    return [
        'id' => 4,
        'subject' => 'Tornado Warning',
        'category' => 'weather_alerts',
        'category_label' => 'Weather Alerts',
        'dispatched_at' => now()->subMinutes(5)->toIso8601String(),
        'channels' => [
            ['type' => 'discord', 'type_label' => 'Discord', 'status' => 'success', 'label' => 'Team Discord'],
            ['type' => 'pushover', 'type_label' => 'Pushover', 'status' => 'failure', 'label' => null],
        ],
        'has_failure' => true,
        'rule_name' => 'Storm warnings',
        'product_url' => 'https://wx.test/view-product/abc',
        ...$overrides,
    ];
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('the detail shows the subject, rule, and each channel with its status', function () {
    Http::fake(['wx.test/api/v1/alerts/4' => Http::response(['data' => detailPayload()])]);

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertSee('Tornado Warning')
        ->assertSee('Storm warnings')
        ->assertSee('Team Discord')
        ->assertSee('Delivered')
        ->assertSee('Pushover')
        ->assertSee('Failed');

    expect((new LocalCache)->detail(4)['rule_name'])->toBe('Storm warnings');
});

test('a delivery with no category shows only the time under the subject', function () {
    Http::fake(['wx.test/api/v1/alerts/4' => Http::response(['data' => detailPayload(['category' => null, 'category_label' => null])])]);

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertElement('text', fn (array $text) => ($text['props']['text'] ?? null) === '5 minutes ago')
        ->assertDontSee('· ');
});

test('View full product opens the public page in the in-app browser', function () {
    Http::fake(['*' => Http::response(['data' => detailPayload()])]);

    Native::test(AlertDetail::class, ['id' => 4])->tap('View full product');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/abc']);
});

test('no button is shown when the alert has no product page', function () {
    Http::fake(['*' => Http::response(['data' => detailPayload(['product_url' => null])])]);

    Native::test(AlertDetail::class, ['id' => 4])->assertDontSee('View full product');
});

test('offline, an alert opened before is shown from the cache with a notice', function () {
    (new LocalCache)->storeDetail(detailPayload());
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertDetail::class, ['id' => 4])
        ->assertSee('Storm warnings')
        ->assertSee("You're offline");
});

test('offline, an alert never opened says it needs a connection', function () {
    Http::fake(fn () => throw new ConnectionException('no route'));

    Native::test(AlertDetail::class, ['id' => 4])->assertSee('Connect to the internet to see this alert');
});

test('an alert that no longer exists says so', function () {
    Http::fake(['*' => Http::response([], 404)]);

    Native::test(AlertDetail::class, ['id' => 4])->assertSee('This alert is no longer available');
});

test('a refused token returns to sign in', function () {
    Http::fake(['*' => Http::response([], 401)]);

    Native::test(AlertDetail::class, ['id' => 4])->assertReplacedWith('/sign-in');

    expect((new TokenStore)->token())->toBeNull();
});

test('a signed-out visitor is sent to sign in', function () {
    (new TokenStore)->forget();

    Native::test(AlertDetail::class, ['id' => 4])->assertReplacedWith('/sign-in');
});
