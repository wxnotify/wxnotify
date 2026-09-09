<?php

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    // A 1x1-degree square roughly over eastern New York, matching the
    // fixture style already used in ResolveLocationZonesTest.
    DB::table('us_states')->insert([
        'state' => 'NY', 'name' => 'New York', 'fips' => '36', 'lon' => -73.5, 'lat' => 42.5,
        'boundary' => DB::raw("ST_SetSRID(ST_Multi(ST_GeomFromText('POLYGON((-74 42, -73 42, -73 43, -74 43, -74 42))')), 4269)"),
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

test('a coordinate inside a state polygon resolves that state\'s primary timezone', function () {
    $timezone = (new ResolveTimezoneFromCoordinates)(42.5, -73.5);

    expect($timezone)->toBe('America/New_York');
});

test('a coordinate outside every state polygon resolves to null', function () {
    $timezone = (new ResolveTimezoneFromCoordinates)(0.0, 0.0);

    expect($timezone)->toBeNull();
});
