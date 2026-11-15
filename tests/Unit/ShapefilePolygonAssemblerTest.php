<?php

use App\Support\Shapefiles\ShapefilePolygonAssembler;

test('a single clockwise ring becomes one polygon with no holes', function () {
    $parts = [
        ['points' => [
            ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
        ]],
    ];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt($parts);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0)))');
});

test('two disjoint clockwise rings become two separate polygons', function () {
    $ringA = ['points' => [
        ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
    ]];
    $ringB = ['points' => [
        ['x' => 20, 'y' => 20], ['x' => 20, 'y' => 30], ['x' => 30, 'y' => 30], ['x' => 30, 'y' => 20], ['x' => 20, 'y' => 20],
    ]];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt([$ringA, $ringB]);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0)),((20 20,20 30,30 30,30 20,20 20)))');
});

test('a counter-clockwise ring following a clockwise ring becomes a hole in it', function () {
    $outer = ['points' => [
        ['x' => 0, 'y' => 0], ['x' => 0, 'y' => 10], ['x' => 10, 'y' => 10], ['x' => 10, 'y' => 0], ['x' => 0, 'y' => 0],
    ]];
    $hole = ['points' => [
        ['x' => 2, 'y' => 2], ['x' => 8, 'y' => 2], ['x' => 8, 'y' => 8], ['x' => 2, 'y' => 8], ['x' => 2, 'y' => 2],
    ]];

    $wkt = ShapefilePolygonAssembler::toMultiPolygonWkt([$outer, $hole]);

    expect($wkt)->toBe('MULTIPOLYGON(((0 0,0 10,10 10,10 0,0 0),(2 2,8 2,8 8,2 8,2 2)))');
});

test('rings compacted record by record assemble to the same WKT as all the parts at once', function () {
    $square = fn (int $at): array => ['points' => [
        ['x' => $at, 'y' => $at], ['x' => $at, 'y' => $at + 10], ['x' => $at + 10, 'y' => $at + 10], ['x' => $at + 10, 'y' => $at], ['x' => $at, 'y' => $at],
    ]];
    $hole = ['points' => [
        ['x' => 2, 'y' => 2], ['x' => 8, 'y' => 2], ['x' => 8, 'y' => 8], ['x' => 2, 'y' => 8], ['x' => 2, 'y' => 2],
    ]];

    // The second record starts with a counter-clockwise ring: joined with the first record's parts
    // it is a hole in the first record's last polygon, exactly as before.
    $first = [$square(0), $hole];
    $second = [$hole, $square(20)];

    $rings = [...ShapefilePolygonAssembler::compactRings($first), ...ShapefilePolygonAssembler::compactRings($second)];

    expect(ShapefilePolygonAssembler::toMultiPolygonWktFromRings($rings))
        ->toBe(ShapefilePolygonAssembler::toMultiPolygonWkt([...$first, ...$second]));
});

test('compacting a part keeps its WKT and whether it is clockwise', function () {
    $clockwise = ['points' => [['x' => 0, 'y' => 0], ['x' => 0, 'y' => 1], ['x' => 1, 'y' => 1], ['x' => 1, 'y' => 0], ['x' => 0, 'y' => 0]]];

    expect(ShapefilePolygonAssembler::compactRings([$clockwise]))
        ->toBe([['wkt' => '0 0,0 1,1 1,1 0,0 0', 'clockwise' => true]]);
});
