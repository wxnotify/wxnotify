<?php

use App\Actions\Weather\FormatMotionVector;
use App\Models\ActiveWwa;

test('formats a real motion vector into a compass direction and mph', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => 231, 'motion_speed_kt' => 34]);

    $result = (new FormatMotionVector)($wwa);

    expect($result)->toBe('Moving SW at 39 mph');
});

test('rounds 0 degrees to North and 360 degrees also to North', function () {
    $north = ActiveWwa::factory()->make(['motion_direction_degrees' => 0, 'motion_speed_kt' => 10]);
    $alsoNorth = ActiveWwa::factory()->make(['motion_direction_degrees' => 360, 'motion_speed_kt' => 10]);

    expect((new FormatMotionVector)($north))->toBe('Moving N at 12 mph')
        ->and((new FormatMotionVector)($alsoNorth))->toBe('Moving N at 12 mph');
});

test('normalizes a negative degree value instead of producing an undefined array offset', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => -30, 'motion_speed_kt' => 10]);

    expect((new FormatMotionVector)($wwa))->toBe('Moving NNW at 12 mph');
});

test('returns null when the ActiveWwa is null', function () {
    expect((new FormatMotionVector)(null))->toBeNull();
});

test('returns null when motion_direction_degrees is missing', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => null, 'motion_speed_kt' => 34]);

    expect((new FormatMotionVector)($wwa))->toBeNull();
});

test('returns null when motion_speed_kt is missing', function () {
    $wwa = ActiveWwa::factory()->make(['motion_direction_degrees' => 231, 'motion_speed_kt' => null]);

    expect((new FormatMotionVector)($wwa))->toBeNull();
});
