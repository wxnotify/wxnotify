<?php

use App\Support\LocalCache;

function recentSearch(string $q = 'afd', array $overrides = []): array
{
    return ['q' => $q, 'type' => null, 'type_label' => null, 'wfo' => '', 'period' => '', ...$overrides];
}

test('searches are remembered newest first, and a repeat moves to the top instead of duplicating', function () {
    $cache = new LocalCache;

    expect($cache->recentSearches())->toBe([]);

    $cache->rememberSearch(recentSearch('afd'));
    $cache->rememberSearch(recentSearch('tornado'));
    $cache->rememberSearch(recentSearch('AFD'));

    expect(array_column($cache->recentSearches(), 'q'))->toBe(['AFD', 'tornado']);
});

test('the same text with a different filter is a different search', function () {
    $cache = new LocalCache;

    $cache->rememberSearch(recentSearch('afd'));
    $cache->rememberSearch(recentSearch('afd', ['wfo' => 'MOB']));
    $cache->rememberSearch(recentSearch('afd', ['type' => 4, 'type_label' => 'Tornado Warning']));
    $cache->rememberSearch(recentSearch('afd', ['period' => 'week']));

    expect($cache->recentSearches())->toHaveCount(4);
});

test('only the last 10 searches are kept', function () {
    $cache = new LocalCache;

    foreach (range(1, 12) as $n) {
        $cache->rememberSearch(recentSearch("search {$n}"));
    }

    $kept = array_column($cache->recentSearches(), 'q');

    expect($kept)->toHaveCount(10)->and($kept[0])->toBe('search 12')->and($kept[9])->toBe('search 3');
});

test('searches can be cleared, and sign-out wipes them', function () {
    $cache = new LocalCache;
    $cache->rememberSearch(recentSearch());

    $cache->forgetSearches();

    expect($cache->recentSearches())->toBe([]);

    $cache->rememberSearch(recentSearch());
    $cache->wipe();

    expect($cache->recentSearches())->toBe([]);
});
