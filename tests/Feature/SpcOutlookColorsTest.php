<?php

use App\Support\SpcOutlookColors;

test('each convective outlook category has its SPC colour and anything else is grey', function (string $label, string $color) {
    expect(SpcOutlookColors::for($label))->toBe($color);
})->with([
    'TSTM' => ['TSTM', '#c1e9c1'],
    'MRGL' => ['MRGL', '#66c57d'],
    'SLGT' => ['SLGT', '#f6f67b'],
    'ENH' => ['ENH', '#e6b366'],
    'MDT' => ['MDT', '#e67f7f'],
    'HIGH' => ['HIGH', '#ff7cff'],
    'unknown' => ['SIGN', '#6b7280'],
    'lower case' => ['slgt', '#6b7280'],
]);

test('the legend lists the categories from lowest to highest with their names and colours', function () {
    expect(SpcOutlookColors::legend())->toBe([
        ['label' => 'TSTM', 'name' => 'General thunder', 'color' => '#c1e9c1'],
        ['label' => 'MRGL', 'name' => 'Marginal', 'color' => '#66c57d'],
        ['label' => 'SLGT', 'name' => 'Slight', 'color' => '#f6f67b'],
        ['label' => 'ENH', 'name' => 'Enhanced', 'color' => '#e6b366'],
        ['label' => 'MDT', 'name' => 'Moderate', 'color' => '#e67f7f'],
        ['label' => 'HIGH', 'name' => 'High', 'color' => '#ff7cff'],
    ]);
});
