<?php

use App\Actions\Testing\GenerateTestProductPolygon;

test('generates a 4-point diamond around a center point at the given radius', function () {
    $points = (new GenerateTestProductPolygon)->fromPointAndRadius(lat: 36.0, lon: -94.0, radiusMiles: 10);

    expect($points)->toHaveCount(4);
    // North point: same lon, higher lat by roughly radius/69 degrees.
    expect($points[0]['lat'])->toBeGreaterThan(36.0)
        ->and($points[0]['lon'])->toEqual(-94.0, 0.0001);
    // East point: same lat, higher lon.
    expect($points[1]['lat'])->toEqual(36.0, 0.0001)
        ->and($points[1]['lon'])->toBeGreaterThan(-93.9);
    // South point: same lon, lower lat.
    expect($points[2]['lat'])->toBeLessThan(36.0)
        ->and($points[2]['lon'])->toEqual(-94.0, 0.0001);
    // West point: same lat, lower lon.
    expect($points[3]['lat'])->toEqual(36.0, 0.0001)
        ->and($points[3]['lon'])->toBeLessThan(-94.1);
});

test('parses a raw lat,lon-per-line textarea into points, skipping malformed lines', function () {
    $raw = "36.0,-94.0\nnot a coordinate\n36.5,-94.5\n\n37.0,-95.0";

    $points = (new GenerateTestProductPolygon)->fromRawText($raw);

    expect($points)->toBe([
        ['lat' => 36.0, 'lon' => -94.0],
        ['lat' => 36.5, 'lon' => -94.5],
        ['lat' => 37.0, 'lon' => -95.0],
    ]);
});

test('raw text parsing returns null when fewer than 3 valid points are present', function () {
    $points = (new GenerateTestProductPolygon)->fromRawText("36.0,-94.0\n36.5,-94.5");

    expect($points)->toBeNull();
});
