<?php

use App\Support\LocalCache;

test('the last active alerts response is kept and wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->activeAlerts())->toBeNull();

    $body = ['data' => [['id' => 1, 'event' => 'Tornado Warning', 'family' => 'warning', 'places' => ['Home'], 'expires' => null, 'view_url' => null]], 'meta' => ['has_places' => true, 'generated_at' => '2026-10-12T14:14:00+00:00']];
    $cache->rememberActiveAlerts($body);

    expect($cache->activeAlerts())->toBe($body);

    $cache->rememberActiveAlerts(['data' => [], 'meta' => ['has_places' => false, 'generated_at' => '2026-10-12T15:00:00+00:00']]);

    expect($cache->activeAlerts()['data'])->toBe([])->and($cache->activeAlerts()['meta']['has_places'])->toBeFalse();

    $cache->wipe();

    expect($cache->activeAlerts())->toBeNull();
});
