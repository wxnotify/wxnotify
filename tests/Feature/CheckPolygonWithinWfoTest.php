<?php

use App\Actions\Testing\CheckPolygonWithinWfo;
use Illuminate\Support\Facades\DB;

function insertTwcBoundary(): void
{
    DB::table('cwa_boundaries')->insert([
        'wfo' => 'TWC', 'cwa' => 'TWC', 'city' => 'Tucson', 'st' => 'AZ', 'citystate' => 'Tucson AZ',
        'lon' => -110.9, 'lat' => 32.2,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-112 31, -109 31, -109 34, -112 34, -112 31))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('returns true when a polygon\'s centroid falls within the given WFO\'s boundary', function () {
    insertTwcBoundary();

    $points = [
        ['lat' => 32.22, 'lon' => -110.98],
        ['lat' => 32.24, 'lon' => -110.96],
        ['lat' => 32.20, 'lon' => -110.96],
    ];

    expect((new CheckPolygonWithinWfo)($points, 'TWC'))->toBeTrue();
});

test('returns false when a polygon\'s centroid falls well outside the given WFO\'s boundary', function () {
    insertTwcBoundary();

    // Far outside the fixture's boundary box -- middle of the Atlantic ocean.
    $points = [
        ['lat' => 35.0, 'lon' => -40.0],
        ['lat' => 35.2, 'lon' => -40.2],
        ['lat' => 34.8, 'lon' => -40.2],
    ];

    expect((new CheckPolygonWithinWfo)($points, 'TWC'))->toBeFalse();
});

test('returns false for an unknown WFO code', function () {
    $points = [['lat' => 32.22, 'lon' => -110.98], ['lat' => 32.24, 'lon' => -110.96], ['lat' => 32.20, 'lon' => -110.96]];

    expect((new CheckPolygonWithinWfo)($points, 'ZZZ'))->toBeFalse();
});
