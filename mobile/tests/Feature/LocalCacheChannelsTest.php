<?php

use App\Support\LocalCache;

test('the channel list is remembered and wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->channels())->toBeNull();

    $cache->rememberChannels([['id' => 1, 'label' => 'Email']]);

    expect($cache->channels())->toBe([['id' => 1, 'label' => 'Email']]);

    $cache->wipe();

    expect($cache->channels())->toBeNull();
});
