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
