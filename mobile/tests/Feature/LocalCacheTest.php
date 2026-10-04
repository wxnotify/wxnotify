<?php

use App\Models\CachedAlert;
use App\Support\LocalCache;

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
