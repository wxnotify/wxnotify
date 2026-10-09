<?php

use App\Actions\Pipeline\ParseLatLonPolygon;

test('decodes a warning-style polygon with a wrapped continuation line', function () {
    $text = "LAT...LON 4031 10526 4040 10501 4012 10490\n      3995 10510\nTIME...MOT...LOC 1805Z 240DEG 25KT 4020 10500\n";

    $points = (new ParseLatLonPolygon)($text);

    expect($points)->toHaveCount(4)
        ->and($points[0])->toEqual(['lat' => 40.31, 'lon' => -105.26])
        ->and($points[3])->toEqual(['lat' => 39.95, 'lon' => -105.10]);
});

test('decodes a combined-token polygon, restoring a dropped leading 1 on a longitude of 100 or more', function () {
    $points = (new ParseLatLonPolygon)("LAT...LON 33311429 33591398 34281370\n\n");

    expect($points)->toHaveCount(3)
        ->and($points[0])->toEqual(['lat' => 33.31, 'lon' => -114.29]);
});

test('returns null without a LAT...LON line, and without enough coordinates', function () {
    expect((new ParseLatLonPolygon)("No polygon here.\n"))->toBeNull()
        ->and((new ParseLatLonPolygon)("LAT...LON 4031 10526 4040 10501\n\n"))->toBeNull();
});
