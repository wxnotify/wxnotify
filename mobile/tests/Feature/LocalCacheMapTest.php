<?php

use App\Support\LocalCache;

test('the cached snapshot keeps the list data without geometry or layers and is wiped on sign-out', function () {
    $cache = new LocalCache;

    expect($cache->map())->toBeNull();

    $cache->rememberMap([
        'center' => ['lat' => 40.0, 'lng' => -105.0, 'source' => 'phone', 'label' => 'Pixel'],
        'phone' => ['lat' => 40.0, 'lng' => -105.0, 'updated_at' => null, 'stale' => false],
        'places' => [['id' => 1, 'label' => 'Home', 'type' => 'stationary', 'lat' => 40.0, 'lng' => -105.0, 'stale' => false]],
        'warnings' => [['id' => 7, 'event' => 'Tornado Warning', 'family' => 'warning', 'color' => '#ff0000', 'headline' => null, 'expires' => null, 'view_url' => null, 'geometry' => ['type' => 'GeometryCollection', 'geometries' => []]]],
        'layers' => ['attribution' => 'x'],
        'generated_at' => '2026-10-10T18:00:00+00:00',
    ]);

    $cached = $cache->map();

    expect($cached['warnings'][0])->not->toHaveKey('geometry')
        ->and($cached['warnings'][0]['event'])->toBe('Tornado Warning')
        ->and($cached)->not->toHaveKey('layers')
        ->and($cached['places'][0]['label'])->toBe('Home')
        ->and($cached['generated_at'])->toBe('2026-10-10T18:00:00+00:00');

    $cache->wipe();

    expect($cache->map())->toBeNull();
});
