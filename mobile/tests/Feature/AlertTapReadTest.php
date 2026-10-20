<?php

use App\NativeComponents\AlertOpen;
use App\NativeComponents\ProductLink;
use App\Services\AlertReadState;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->cache = new LocalCache;
    $this->cache->replaceFeed([]);
    $this->cache->rememberUnreadCount(3);
});

function tapReadPageLink(): string
{
    return rtrim(strtr(base64_encode('https://wx.test/view-product/kbou-tor-202610121500'), '+/', '-_'), '=');
}

function tapReadBatchRef(): string
{
    return 'b.0b9f6f5e-3c1d-4a2b-9e8f-1a2b3c4d5e6f';
}

/**
 * @return array<int, string> the refs sent to the read-by-reference endpoint, in order
 */
function tapReadSentRefs(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => $pair[0]->method() === 'POST' && $pair[0]->url() === 'https://wx.test/api/v1/alerts/read-ref')
        ->map(fn (array $pair) => $pair[0]['ref'])
        ->values()->all();
}

test('a product tap link with a reference opens the page over Alerts and reads the alert', function () {
    $pageOpenAtRead = null;
    Http::fake(['wx.test/api/v1/alerts/read-ref' => function () use (&$pageOpenAtRead) {
        $pageOpenAtRead = $this->browser->inAppUrls !== [];

        return Http::response(['data' => ['unread_count' => 2]]);
    }]);

    Native::visit('/product/'.tapReadPageLink().'/p.7.88')->assertReplacedWith('/');

    expect($pageOpenAtRead)->toBeTrue()
        ->and($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/kbou-tor-202610121500'])
        ->and(tapReadSentRefs())->toBe(['p.7.88'])
        ->and($this->cache->unreadCount())->toBe(2)
        ->and($this->appBadge->counts)->toBe([2]);
});

test('a product tap link without a reference still opens the page and reads nothing', function () {
    Http::fake();

    Native::visit('/product/'.tapReadPageLink())->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/kbou-tor-202610121500']);
    Http::assertNothingSent();
});

test('an alert tap link queues the read and replaces itself with Alerts at once, without a request', function (Closure $response) {
    Http::fake(['wx.test/*' => $response]);

    Native::visit('/alert/'.tapReadBatchRef())->assertReplacedWith('/');

    expect($this->cache->pendingRefs())->toBe([tapReadBatchRef()])
        ->and($this->browser->inAppUrls)->toBe([]);
    Http::assertNothingSent();
})->with([
    'a working server' => [fn () => fn () => Http::response(['data' => ['unread_count' => 0]])],
    'a server that is down' => [fn () => fn () => throw new ConnectionException('timed out')],
]);

test('the alert tap\'s queued read is replayed when Alerts mounts, before its feed load, and sets the badge', function () {
    Http::fake([
        'wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 1]]),
        'wx.test/api/v1/alerts?*' => Http::response([], 500),
        'wx.test/api/v1/active-alerts' => Http::response(['data' => [], 'meta' => ['has_places' => true, 'generated_at' => now()->toIso8601String()]]),
    ]);

    Native::visit('/alert/p.7.88')->assertReplacedWith('/');
    Native::visit('/');

    $paths = collect(Http::recorded())->map(fn (array $pair) => parse_url($pair[0]->url(), PHP_URL_PATH))->values()->all();

    expect(tapReadSentRefs())->toBe(['p.7.88'])
        ->and(array_search('/api/v1/alerts/read-ref', $paths, true))->toBeLessThan(array_search('/api/v1/alerts', $paths, true))
        ->and($this->appBadge->counts)->toBe([1])
        ->and($this->cache->pendingRefs())->toBe([]);
});

test('an invalid reference is ignored and the page or Alerts still opens', function (string $ref) {
    Http::fake();

    Native::test(ProductLink::class, ['link' => tapReadPageLink(), 'ref' => $ref])->assertReplacedWith('/');
    Native::test(AlertOpen::class, ['ref' => $ref])->assertReplacedWith('/');

    expect($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/kbou-tor-202610121500']);
    Http::assertNothingSent();
})->with([
    'garbage' => ['drop table'],
    'unknown kind' => ['x.1.2'],
    'upper-case batch' => ['b.0B9F6F5E-3C1D-4A2B-9E8F-1A2B3C4D5E6F'],
    'too many digits' => ['p.1.'.str_repeat('9', 19)],
]);

test('a server that is down never stops the page opening, and the read is queued', function (Closure $response) {
    $pageOpenAtRead = null;
    Http::fake(['wx.test/*' => function (Request $request) use ($response, &$pageOpenAtRead) {
        $pageOpenAtRead = $this->browser->inAppUrls !== [];

        return $response($request);
    }]);

    Native::test(ProductLink::class, ['link' => tapReadPageLink(), 'ref' => 'p.7.88'])->assertReplacedWith('/');

    expect($pageOpenAtRead)->toBeTrue()
        ->and($this->browser->inAppUrls)->toBe(['https://wx.test/view-product/kbou-tor-202610121500'])
        ->and($this->cache->pendingRefs())->toBe(['p.7.88'])
        ->and($this->cache->unreadCount())->toBe(3);
})->with([
    'dropped connection' => [fn () => fn () => throw new ConnectionException('no route')],
    'server error' => [fn () => fn () => Http::response([], 500)],
    'rate limited' => [fn () => fn () => Http::response([], 429)],
]);

test('offline, an alert tap link queues the read without a request and still shows Alerts', function () {
    $this->network->connected = false;
    Http::fake();

    Native::test(AlertOpen::class, ['ref' => 'p.0.88'])->assertReplacedWith('/');

    expect($this->cache->pendingRefs())->toBe(['p.0.88']);
    Http::assertNothingSent();
});

test('a queued reference is replayed by the next retry and stores the server count', function () {
    $this->cache->rememberPendingRefs(['p.7.88', tapReadBatchRef()]);
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::sequence()
        ->push(['data' => ['unread_count' => 2]])
        ->push(['data' => ['unread_count' => 1]])]);

    app(AlertReadState::class)->retryPending();

    expect(tapReadSentRefs())->toBe(['p.7.88', tapReadBatchRef()])
        ->and($this->cache->pendingRefs())->toBe([])
        ->and($this->cache->unreadCount())->toBe(1)
        ->and($this->appBadge->last())->toBe(1);
});

test('a successful read is replayed exactly once more on the next feed load, then forgotten', function () {
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 0]])]);
    $state = app(AlertReadState::class);

    $state->openByRef('p.7.88');
    $state->retryPending();
    $state->retryPending();
    $state->retryPending();

    expect(tapReadSentRefs())->toBe(['p.7.88', 'p.7.88'])
        ->and($this->cache->pendingRefs())->toBe([])
        ->and($this->cache->secondPassRefs())->toBe([]);
});

test('a queued read gets its second pass on the feed load after the one that replayed it, not the same one', function () {
    $this->cache->rememberPendingRefs(['p.7.88']);
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 0]])]);
    $state = app(AlertReadState::class);

    $state->retryPending();
    expect(tapReadSentRefs())->toBe(['p.7.88'])->and($this->cache->secondPassRefs())->toBe(['p.7.88']);

    $state->retryPending();
    $state->retryPending();
    expect(tapReadSentRefs())->toBe(['p.7.88', 'p.7.88'])->and($this->cache->secondPassRefs())->toBe([]);
});

test('a second pass that cannot reach the server is kept for the next feed load', function () {
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 0]])]);
    app(AlertReadState::class)->openByRef('p.7.88');
    $this->network->connected = false;

    app(AlertReadState::class)->retryPending();

    expect($this->cache->secondPassRefs())->toBe(['p.7.88']);
});

test('a replay that fails again stops and keeps the queue', function (int $status) {
    $this->cache->rememberPendingRefs(['p.7.88', 'p.7.89']);
    Http::fake(['wx.test/*' => Http::response([], $status)]);

    app(AlertReadState::class)->retryPending();

    expect($this->cache->pendingRefs())->toBe(['p.7.88', 'p.7.89']);
    Http::assertSentCount(1);
})->with(['rate limited' => [429], 'refused token' => [401]]);

test('a reference the server refuses is dropped, not queued or replayed', function () {
    Http::fake(['wx.test/*' => Http::response(['message' => 'The ref field format is invalid.', 'errors' => ['ref' => ['The ref field format is invalid.']]], 422)]);

    app(AlertReadState::class)->openByRef('p.7.88');
    $this->cache->rememberPendingRefs(['p.7.89']);
    app(AlertReadState::class)->retryPending();

    expect($this->cache->pendingRefs())->toBe([]);
    Http::assertSentCount(2);
});

test('a refused token neither queues the read nor retries it', function () {
    Http::fake(['wx.test/*' => Http::response([], 401)]);

    app(AlertReadState::class)->openByRef('p.7.88');

    expect($this->cache->pendingRefs())->toBe([]);
    Http::assertSentCount(1);
});

test('a signed-out phone sends nothing for a tap link', function () {
    (new TokenStore)->forget();
    Http::fake();

    Native::test(AlertOpen::class, ['ref' => 'p.7.88'])->assertReplacedWith('/');

    Http::assertNothingSent();
    expect($this->cache->pendingRefs())->toBe([]);
});

test('a successful tap read makes the Alerts feed reload on its next showing', function () {
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 2]])]);
    expect($this->cache->syncedAt())->not->toBeNull();

    app(AlertReadState::class)->openByRef('p.7.88');

    expect($this->cache->syncedAt())->toBeNull();
});

test('the queue of references is bounded, keeps the newest and is wiped at sign-out', function () {
    $this->network->connected = false;

    foreach (range(1, 25) as $product) {
        app(AlertReadState::class)->openByRef("p.7.{$product}");
    }
    app(AlertReadState::class)->openByRef('p.7.25');

    expect($this->cache->pendingRefs())->toHaveCount(20)
        ->and($this->cache->pendingRefs()[0])->toBe('p.7.6')
        ->and($this->cache->pendingRefs()[19])->toBe('p.7.25');

    $this->cache->rememberSecondPassRefs(['p.7.1']);
    $this->cache->wipe();

    expect($this->cache->pendingRefs())->toBe([])->and($this->cache->secondPassRefs())->toBe([]);
});

test('the read call posts the reference to the read-by-reference endpoint with the app token', function () {
    Http::fake(['wx.test/api/v1/alerts/read-ref' => Http::response(['data' => ['unread_count' => 4]])]);

    app(AlertReadState::class)->openByRef('p.7.88');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://wx.test/api/v1/alerts/read-ref'
        && $request->data() === ['ref' => 'p.7.88']
        && $request->hasHeader('Authorization', 'Bearer 9|secret'));
});
