<?php

use App\Support\LocalCache;

test('rules, locations and the form options are remembered and read back', function () {
    $cache = new LocalCache;

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();

    $cache->rememberRules([['id' => 1, 'display_name' => 'Freeze']]);
    $cache->rememberLocations([['id' => 2, 'label' => 'Home']], ['can_create' => false, 'limit' => 1]);
    $cache->rememberOptions(['categories' => [['value' => 'threshold']]]);

    expect($cache->rules())->toBe([['id' => 1, 'display_name' => 'Freeze']])
        ->and($cache->locations())->toBe([['id' => 2, 'label' => 'Home']])
        ->and($cache->locationsMeta())->toBe(['can_create' => false, 'limit' => 1])
        ->and($cache->options())->toBe(['categories' => [['value' => 'threshold']]]);
});

test('an empty list is remembered as empty, not as never fetched', function () {
    $cache = new LocalCache;
    $cache->rememberRules([]);

    expect($cache->rules())->toBe([]);
});

test('wiping forgets them all', function () {
    $cache = new LocalCache;
    $cache->rememberRules([['id' => 1]]);
    $cache->rememberLocations([['id' => 2]], ['can_create' => true, 'limit' => null]);
    $cache->rememberOptions(['categories' => []]);

    $cache->wipe();

    expect($cache->rules())->toBeNull()->and($cache->locations())->toBeNull()->and($cache->locationsMeta())->toBeNull()->and($cache->options())->toBeNull();
});
