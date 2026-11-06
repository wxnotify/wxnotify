<?php

use App\Support\NexradSites;

test('the list holds lower-case ids with a name and coordinates', function () {
    $all = NexradSites::all();

    expect($all)->toHaveKey('kfws')
        ->and($all['kfws'])->toHaveKeys(['name', 'lat', 'lng'])
        ->and(array_keys($all))->each->toMatch('/^[a-z0-9]{4}$/');
});

test('exists ignores case and rejects unknown or malformed codes', function () {
    expect(NexradSites::exists('KFWS'))->toBeTrue()
        ->and(NexradSites::exists('kfws'))->toBeTrue()
        ->and(NexradSites::exists('zzzz'))->toBeFalse()
        ->and(NexradSites::exists('../kfws'))->toBeFalse();
});

test('nearest returns the closest site for a point', function () {
    expect(NexradSites::nearest(32.7555, -97.3308))->toBe('kfws')
        ->and(NexradSites::nearest(46.8, -100.8))->toBe('kbis');
});

test('nearest still answers for a point in the open Pacific', function () {
    expect(NexradSites::nearest(30.0, -140.0))->toBeString()->and(NexradSites::exists(NexradSites::nearest(30.0, -140.0)))->toBeTrue();
});
