<?php

use App\Actions\Pipeline\ParseTextMotionDescription;
use Illuminate\Support\Carbon;

/**
 * Four real captured NWS text bulletins, each containing a real
 * TIME...MOT...LOC line -- confirmed present via
 * `grep -rl "TIME...MOT...LOC" database/product_examples/`. The parser is
 * exercised against the FULL raw bulletin text (not a pre-extracted line),
 * matching how every sibling parser in this file (ParseVtecString,
 * ParseNarrativeSections) is actually invoked against a whole product body.
 */
function realBulletinText(string $filename): string
{
    return file_get_contents(base_path("database/product_examples/{$filename}"));
}

test('parses a real SVW.txt TIME...MOT...LOC line (4-digit lat/lon) out of the full bulletin', function () {
    $result = (new ParseTextMotionDescription)(realBulletinText('SVW.txt'), Carbon::parse('2026-09-14'));

    expect($result['motion_observed_at']->toDateTimeString())->toBe('2026-09-14 00:52:00')
        ->and($result['motion_direction_degrees'])->toBe(278)
        ->and($result['motion_speed_kt'])->toBe(15)
        ->and($result['motion_reference_lat'])->toBe(36.56)
        ->and($result['motion_reference_lon'])->toBe(-90.73);
});

test('parses a real TOW.txt TIME...MOT...LOC line out of the full bulletin', function () {
    $result = (new ParseTextMotionDescription)(realBulletinText('TOW.txt'), Carbon::parse('2026-09-14'));

    expect($result['motion_observed_at']->toDateTimeString())->toBe('2026-09-14 17:47:00')
        ->and($result['motion_direction_degrees'])->toBe(183)
        ->and($result['motion_speed_kt'])->toBe(30)
        ->and($result['motion_reference_lat'])->toBe(36.63)
        ->and($result['motion_reference_lon'])->toBe(-88.10);
});

test('takes only the first lat/lon pair when a real bulletin lists several storm cells on one motion vector', function () {
    // SPS.txt's real line: "TIME...MOT...LOC 2321Z 275DEG 34KT 3916 9604 3880 9604"
    $result = (new ParseTextMotionDescription)(realBulletinText('SPS.txt'), Carbon::parse('2026-09-14'));

    expect($result['motion_reference_lat'])->toBe(39.16)
        ->and($result['motion_reference_lon'])->toBe(-96.04);
});

test('decodes a real 5-digit longitude (>= 100 degrees) from DSW.txt', function () {
    $result = (new ParseTextMotionDescription)(realBulletinText('DSW.txt'), Carbon::parse('2026-09-14'));

    expect($result['motion_reference_lat'])->toBe(33.15)
        ->and($result['motion_reference_lon'])->toBe(-111.95);
});

test('returns an empty array, not null or an exception, when no TIME...MOT...LOC line is present', function () {
    $result = (new ParseTextMotionDescription)("BULLETIN - EAS ACTIVATION REQUESTED\nFlash Flood Warning\n", Carbon::parse('2026-09-14'));

    expect($result)->toBe([]);
});

test('returns an empty array for an empty string input', function () {
    expect((new ParseTextMotionDescription)('', Carbon::parse('2026-09-14')))->toBe([]);
});
