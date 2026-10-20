<?php

use App\Models\CachedAlert;
use App\Support\LocalCache;
use Illuminate\Support\Facades\DB;

function feedEntry(int $id, string $at = '2026-10-04T12:00:00+00:00'): array
{
    return ['id' => $id, 'subject' => "Alert {$id}", 'dispatched_at' => $at, 'channels' => [], 'has_failure' => false];
}

beforeEach(fn () => $this->cache = new LocalCache);

test('an empty cache has no feed, sync time, profile or detail', function () {
    expect($this->cache->feed())->toBe([])
        ->and($this->cache->syncedAt())->toBeNull()
        ->and($this->cache->profile())->toBeNull()
        ->and($this->cache->detail(1))->toBeNull();
});

test('replacing the feed stores entries newest first and stamps the sync time', function () {
    $this->cache->replaceFeed([
        feedEntry(2, '2026-10-04T12:05:00+00:00'),
        feedEntry(1, '2026-10-04T12:00:00+00:00'),
    ]);

    expect(array_column($this->cache->feed(), 'id'))->toBe([2, 1])
        ->and($this->cache->syncedAt())->not->toBeNull();
});

test('a refresh replaces the feed: entries no longer in it disappear', function () {
    $this->cache->replaceFeed([feedEntry(1), feedEntry(2)]);
    $this->cache->replaceFeed([feedEntry(2), feedEntry(3)]);

    expect(array_column($this->cache->feed(), 'id'))->toContain(2, 3)->not->toContain(1)
        ->and(CachedAlert::count())->toBe(2);
});

test('only the configured number of entries is kept', function () {
    config(['wxnotify.feed_cache_size' => 3]);

    $this->cache->replaceFeed(array_map(fn ($i) => feedEntry($i, "2026-10-04T12:0{$i}:00+00:00"), range(1, 5)));

    expect($this->cache->feed())->toHaveCount(3)
        ->and(array_column($this->cache->feed(), 'id'))->toBe([5, 4, 3]);
});

test('an opened detail is kept even after it leaves the feed', function () {
    $this->cache->replaceFeed([feedEntry(1)]);
    $this->cache->storeDetail([...feedEntry(1), 'rule_name' => 'Storm warnings']);
    $this->cache->replaceFeed([feedEntry(2)]);

    expect($this->cache->detail(1)['rule_name'])->toBe('Storm warnings')
        ->and(array_column($this->cache->feed(), 'id'))->toBe([2]);
});

test('a detail opened for an alert that is not in the feed is stored without joining it', function () {
    $this->cache->storeDetail([...feedEntry(9), 'rule_name' => null]);

    expect($this->cache->detail(9))->not->toBeNull()
        ->and($this->cache->feed())->toBe([]);
});

test('the profile is remembered', function () {
    $this->cache->rememberProfile(['name' => 'Pat', 'timezone' => 'America/Chicago']);

    expect($this->cache->profile())->toBe(['name' => 'Pat', 'timezone' => 'America/Chicago']);
});

test('wiping removes everything', function () {
    $this->cache->replaceFeed([feedEntry(1)]);
    $this->cache->storeDetail([...feedEntry(1), 'rule_name' => 'x']);
    $this->cache->rememberProfile(['name' => 'Pat']);

    $this->cache->wipe();

    expect($this->cache->feed())->toBe([])
        ->and($this->cache->detail(1))->toBeNull()
        ->and($this->cache->profile())->toBeNull()
        ->and($this->cache->syncedAt())->toBeNull()
        ->and(CachedAlert::count())->toBe(0);
});

test('the cached feed keeps each alert\'s read flag, and an entry without one counts as read', function () {
    $this->cache->replaceFeed([[...feedEntry(1), 'read' => false], [...feedEntry(2), 'read' => true], feedEntry(3)]);

    expect(array_column($this->cache->feed(), 'read', 'id'))->toBe([3 => true, 2 => true, 1 => false]);
});

test('an alert cached before the read flag existed reads as read once the migration runs on the phone', function () {
    $migration = require database_path('migrations/2026_10_20_120000_add_read_to_cached_alerts_table.php');
    $migration->down();
    DB::table('cached_alerts')->insert(['alert_id' => 1, 'sort_at' => now(), 'list' => json_encode(feedEntry(1)), 'in_feed' => true]);

    $migration->up();

    expect($this->cache->feed()[0]['read'])->toBeTrue();
});

test('marking one alert read says whether it was unread', function () {
    $this->cache->replaceFeed([[...feedEntry(1), 'read' => false]]);

    expect($this->cache->markAlertRead(1))->toBeTrue()
        ->and($this->cache->markAlertRead(1))->toBeFalse()
        ->and($this->cache->markAlertRead(99))->toBeFalse()
        ->and($this->cache->feed()[0]['read'])->toBeTrue();
});

test('a detail carries the server\'s read flag into the cache', function () {
    $this->cache->replaceFeed([[...feedEntry(1), 'read' => true]]);

    $this->cache->storeDetail([...feedEntry(1), 'read' => false, 'rule_name' => null]);

    expect($this->cache->feed()[0]['read'])->toBeFalse()
        ->and($this->cache->isCachedRead(1))->toBeFalse()
        ->and($this->cache->isCachedRead(99))->toBeNull();
});

test('wiping forgets the unread count and every pending read', function () {
    $this->cache->rememberUnreadCount(3);
    $this->cache->rememberPendingReads([1, 2]);
    $this->cache->rememberPendingReadAll(through: '2026-10-20T12:00:00.123456Z');
    $this->cache->rememberFeedAsOf('2026-10-20T12:00:00.123456Z');

    $this->cache->wipe();

    expect($this->cache->unreadCount())->toBeNull()
        ->and($this->cache->pendingReads())->toBe([])
        ->and($this->cache->pendingReadAll())->toBeFalse()
        ->and($this->cache->pendingReadAllCutoff())->toBeNull()
        ->and($this->cache->feedAsOf())->toBeNull();
});

test('a pending mark all keeps its cutoff, and one without a cutoff (or from an earlier build) has none', function () {
    $this->cache->rememberPendingReadAll(through: '2026-10-20T12:00:00.123456Z');

    expect($this->cache->pendingReadAll())->toBeTrue()
        ->and($this->cache->pendingReadAllCutoff())->toBe('2026-10-20T12:00:00.123456Z');

    $this->cache->rememberPendingReadAll();

    expect($this->cache->pendingReadAll())->toBeTrue()
        ->and($this->cache->pendingReadAllCutoff())->toBeNull();
});

test('marking all read with a cutoff reads only cached alerts sent at or before it', function () {
    $this->cache->replaceFeed([
        [...feedEntry(1), 'dispatched_at' => '2026-10-20T11:59:00+00:00', 'read' => false],
        [...feedEntry(2), 'dispatched_at' => '2026-10-20T12:00:00+00:00', 'read' => false],
        [...feedEntry(3), 'dispatched_at' => '2026-10-20T12:00:01+00:00', 'read' => false],
    ]);

    $this->cache->markAllAlertsRead('2026-10-20T12:00:00.123456Z');

    expect([1 => $this->cache->isCachedRead(1), 2 => $this->cache->isCachedRead(2), 3 => $this->cache->isCachedRead(3)])
        ->toBe([1 => true, 2 => true, 3 => false]);
});

test('a detail sent after a pending mark all\'s cutoff takes the server\'s unread flag', function () {
    $this->cache->replaceFeed([[...feedEntry(1), 'dispatched_at' => '2026-10-20T12:05:00+00:00', 'read' => true]]);
    $this->cache->rememberPendingReadAll(through: '2026-10-20T12:00:00.123456Z');

    $this->cache->storeDetail([...feedEntry(1), 'dispatched_at' => '2026-10-20T12:05:00+00:00', 'read' => false, 'rule_name' => null]);

    expect($this->cache->isCachedRead(1))->toBeFalse();
});

test('a detail never turns a read still waiting for the server back to unread', function (bool $readAll) {
    $this->cache->replaceFeed([[...feedEntry(1), 'read' => true]]);
    $readAll ? $this->cache->rememberPendingReadAll() : $this->cache->rememberPendingReads([1]);

    $this->cache->storeDetail([...feedEntry(1), 'read' => false, 'rule_name' => null]);

    expect($this->cache->isCachedRead(1))->toBeTrue();
})->with(['a pending read' => [false], 'a pending mark all' => [true]]);
