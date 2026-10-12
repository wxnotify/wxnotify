<?php

use App\Support\LocalCache;

test('the map page is kept for the web view under a version that follows its content, and wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->mapPage())->toBeNull();

    $first = $cache->rememberMapPage('<html>one</html>');

    expect($cache->rememberMapPage('<html>one</html>'))->toBe($first)
        ->and($cache->rememberMapPage('<html>two</html>'))->not->toBe($first)
        ->and($cache->mapPage())->toBe('<html>two</html>');

    $cache->wipe();

    expect($cache->mapPage())->toBeNull();
});
