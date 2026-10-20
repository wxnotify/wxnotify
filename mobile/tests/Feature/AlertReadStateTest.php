<?php

use App\Services\AlertReadState;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, mixed>
 */
function readStateEntry(int $id, bool $read): array
{
    return ['id' => $id, 'subject' => "Alert {$id}", 'dispatched_at' => '2026-10-04T12:00:00+00:00', 'read' => $read, 'channels' => [], 'has_failure' => false];
}

/**
 * @return array<int, bool> read flag by id, as the cached feed now has it
 */
function cachedReadFlags(): array
{
    $flags = array_column((new LocalCache)->feed(), 'read', 'id');
    ksort($flags);

    return $flags;
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
    $this->cache = new LocalCache;
    $this->cache->replaceFeed([readStateEntry(1, false), readStateEntry(2, false), readStateEntry(3, true)]);
    $this->cache->rememberUnreadCount(2);
    $this->state = app(AlertReadState::class);
});

test('opening an unread alert marks it read, stores the server count and sets the badge to it', function () {
    Http::fake(['wx.test/api/v1/alerts/1/read' => Http::response(['data' => ['unread_count' => 1]])]);

    $this->state->open(1);

    expect(cachedReadFlags())->toBe([1 => true, 2 => false, 3 => true])
        ->and($this->cache->unreadCount())->toBe(1)
        ->and($this->appBadge->counts)->toBe([1])
        ->and($this->cache->pendingReads())->toBe([]);
    Http::assertSentCount(1);
});

test('the server count wins over the local guess', function () {
    Http::fake(['wx.test/api/v1/alerts/1/read' => Http::response(['data' => ['unread_count' => 5]])]);

    $this->state->open(1);

    expect($this->cache->unreadCount())->toBe(5)
        ->and($this->appBadge->last())->toBe(5);
});

test('opening an alert already read on this phone sends nothing and leaves the count', function () {
    Http::fake();

    $this->state->open(3);

    expect($this->cache->unreadCount())->toBe(2)
        ->and($this->appBadge->counts)->toBe([2]);
    Http::assertNothingSent();
});

test('opening an alert this phone has not cached asks the server', function () {
    Http::fake(['wx.test/api/v1/alerts/9/read' => Http::response(['data' => ['unread_count' => 2]])]);

    $this->state->open(9);

    Http::assertSent(fn (Request $r) => $r->url() === 'https://wx.test/api/v1/alerts/9/read');
    expect($this->appBadge->last())->toBe(2);
});

test('offline opening still marks it read locally, lowers the count and queues the id', function () {
    $this->network->connected = false;
    Http::fake();

    $this->state->open(1);

    expect(cachedReadFlags()[1])->toBeTrue()
        ->and($this->cache->unreadCount())->toBe(1)
        ->and($this->appBadge->counts)->toBe([1])
        ->and($this->cache->pendingReads())->toBe([1]);
    Http::assertNothingSent();
});

test('a failed read call is queued, never thrown', function (Closure $response) {
    Http::fake(['wx.test/*' => $response]);

    $this->state->open(1);

    expect($this->cache->pendingReads())->toBe([1])
        ->and($this->cache->unreadCount())->toBe(1);
})->with([
    'server error' => [fn () => fn () => Http::response([], 500)],
    'dropped connection' => [fn () => fn () => throw new ConnectionException('no route')],
    'refused token' => [fn () => fn () => Http::response([], 401)],
]);

test('an alert the server says is not in the feed is not queued', function () {
    Http::fake(['wx.test/*' => Http::response([], 404)]);

    $this->state->open(1);

    expect($this->cache->pendingReads())->toBe([])
        ->and(cachedReadFlags()[1])->toBeTrue();
});

test('retrying replays every pending id and stores the last count', function () {
    $this->cache->rememberPendingReads([1, 2]);
    Http::fake([
        'wx.test/api/v1/alerts/1/read' => Http::response(['data' => ['unread_count' => 1]]),
        'wx.test/api/v1/alerts/2/read' => Http::response(['data' => ['unread_count' => 0]]),
    ]);

    $this->state->retryPending();

    expect($this->cache->pendingReads())->toBe([])
        ->and($this->cache->unreadCount())->toBe(0);
    Http::assertSentCount(2);
});

test('a pending id the server no longer has is dropped, and a failing one stays', function () {
    $this->cache->rememberPendingReads([1, 2]);
    Http::fake([
        'wx.test/api/v1/alerts/1/read' => Http::response([], 404),
        'wx.test/api/v1/alerts/2/read' => Http::response([], 500),
    ]);

    $this->state->retryPending();

    expect($this->cache->pendingReads())->toBe([2]);
});

test('a refused token stops the retry after one request and keeps everything pending', function () {
    $this->cache->rememberPendingReads([1, 2]);
    $this->cache->rememberPendingReadAll();
    Http::fake(['wx.test/*' => Http::response([], 401)]);

    $this->state->retryPending();

    expect($this->cache->pendingReads())->toBe([1, 2])
        ->and($this->cache->pendingReadAll())->toBeTrue();
    Http::assertSentCount(1);
});

test('offline the retry sends nothing and keeps the queue', function () {
    $this->cache->rememberPendingReads([1]);
    $this->network->connected = false;
    Http::fake();

    $this->state->retryPending();

    expect($this->cache->pendingReads())->toBe([1]);
    Http::assertNothingSent();
});

test('nothing pending means no request', function () {
    Http::fake();

    $this->state->retryPending();

    Http::assertNothingSent();
});

test('mark all reads every cached alert, sets the badge to 0 and stores the server count', function () {
    Http::fake(['wx.test/api/v1/alerts/read-all' => Http::response(['data' => ['unread_count' => 0]])]);

    $this->state->markAll();

    expect(cachedReadFlags())->toBe([1 => true, 2 => true, 3 => true])
        ->and($this->cache->unreadCount())->toBe(0)
        ->and($this->appBadge->counts)->toBe([0])
        ->and($this->cache->pendingReadAll())->toBeFalse();
});

test('mark all survives a server failure with a pending retry that later goes through', function () {
    $this->cache->rememberPendingReads([7]);
    Http::fakeSequence('wx.test/api/v1/alerts/read-all')
        ->push([], 500)
        ->push(['data' => ['unread_count' => 0]]);

    $this->state->markAll();

    expect(cachedReadFlags())->toBe([1 => true, 2 => true, 3 => true])
        ->and($this->appBadge->counts)->toBe([0])
        ->and($this->cache->pendingReadAll())->toBeTrue();

    $this->state->retryPending();

    expect($this->cache->pendingReadAll())->toBeFalse()
        ->and($this->cache->pendingReads())->toBe([]);
    Http::assertSentCount(2);
});

test('syncing from the server stores the count and sets the badge', function () {
    $this->state->syncFromServer(4);

    expect($this->cache->unreadCount())->toBe(4)
        ->and($this->appBadge->counts)->toBe([4]);
});

test('a fresh feed keeps alerts read that are still waiting for the server, and counts them out', function () {
    $this->cache->rememberPendingReads([1]);

    $entries = $this->state->applyFeed([readStateEntry(1, false), readStateEntry(2, false)], 2);

    expect(array_column($entries, 'read', 'id'))->toBe([1 => true, 2 => false])
        ->and($this->cache->unreadCount())->toBe(1)
        ->and($this->appBadge->last())->toBe(1);
});

test('a fresh feed with a mark all still pending shows everything read and a zero badge', function () {
    $this->cache->rememberPendingReadAll();

    $entries = $this->state->applyFeed([readStateEntry(1, false), readStateEntry(2, false)], 2);

    expect(array_column($entries, 'read', 'id'))->toBe([1 => true, 2 => true])
        ->and($this->appBadge->last())->toBe(0);
});

test('a feed without a count leaves the stored count and the badge alone', function () {
    $this->state->applyFeed([readStateEntry(1, false)], null);

    expect($this->cache->unreadCount())->toBe(2)
        ->and($this->appBadge->counts)->toBe([]);
});
