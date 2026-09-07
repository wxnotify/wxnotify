<?php

use App\Support\Shapefiles\ZoneCountyCorrelationParser;

test('it parses real pipe-delimited correlation lines into column-keyed rows', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n"
        .'NM|201|ABQ|Northwest Plateau|NM201|San Juan|35045|M|nw|36.4270|-108.4064';

    $rows = (new ZoneCountyCorrelationParser)->parse($content);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['state'])->toBe('NM')
        ->and($rows[0]['state_zone'])->toBe('NM201')
        ->and($rows[0]['county'])->toBe('McKinley')
        ->and($rows[0]['fips'])->toBe('35031')
        ->and($rows[0]['lat'])->toBe('36.4270')
        ->and($rows[0]['lon'])->toBe('-108.4064')
        ->and($rows[1]['county'])->toBe('San Juan');
});

test('it skips blank lines', function () {
    $content = "NM|201|ABQ|Northwest Plateau|NM201|McKinley|35031|M|nw|36.4270|-108.4064\n\n";

    expect((new ZoneCountyCorrelationParser)->parse($content))->toHaveCount(1);
});
