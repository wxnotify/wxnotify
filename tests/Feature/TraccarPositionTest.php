<?php

use App\Support\TraccarPosition;

test('a position has a fix unless Traccar flags it invalid or it sits at exactly 0,0', function (array $position, bool $hasFix) {
    expect(TraccarPosition::hasFix($position))->toBe($hasFix);
})->with([
    'normal' => [['latitude' => 39.1, 'longitude' => -94.6], true],
    'explicitly valid' => [['latitude' => 39.1, 'longitude' => -94.6, 'valid' => true], true],
    'flagged invalid' => [['latitude' => 39.1, 'longitude' => -94.6, 'valid' => false], false],
    'null island' => [['latitude' => 0, 'longitude' => 0], false],
    'null island as floats' => [['latitude' => 0.0, 'longitude' => 0.0], false],
    'null island as strings' => [['latitude' => '0', 'longitude' => '0.0'], false],
    'only latitude zero' => [['latitude' => 0, 'longitude' => -80.0], true],
    'only longitude zero' => [['latitude' => 25.0, 'longitude' => 0], true],
]);
