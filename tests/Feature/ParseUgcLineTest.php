<?php

use App\Actions\Pipeline\ParseUgcLine;

test('reads the first zone line of a section', function (string $text, ?array $expected) {
    expect((new ParseUgcLine)($text))->toBe($expected);
})->with([
    'bare numbers after a full code' => ["GMZ650-655-670-675-080000-\n/O.NEW.KMOB.HU.A.1009.261007T1538Z-000000T0000Z/", ['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675']],
    'a range after a bare number' => ["NYZ069-070>075-081200-\n", ['NYZ069', 'NYZ070', 'NYZ071', 'NYZ072', 'NYZ073', 'NYZ074', 'NYZ075']],
    'a range after a full code' => ["GMZ630>636-080000-\n", ['GMZ630', 'GMZ631', 'GMZ632', 'GMZ633', 'GMZ634', 'GMZ635', 'GMZ636']],
    'a full-code range followed by a bare range and a bare number' => ["CAZ110>114-116>118-120-101900-\n", ['CAZ110', 'CAZ111', 'CAZ112', 'CAZ113', 'CAZ114', 'CAZ116', 'CAZ117', 'CAZ118', 'CAZ120']],
    'a zone line wrapped across lines' => ["FLZ201-202-203-\n204>206-080015-\n", ['FLZ201', 'FLZ202', 'FLZ203', 'FLZ204', 'FLZ205', 'FLZ206']],
    'a prefix that changes inside the line' => ["MSZ086-LAZ070-076-111200-\n", ['MSZ086', 'LAZ070', 'LAZ076']],
    'counties and zones together' => ["NYC061-NJZ103-081200-\n", ['NYC061', 'NJZ103']],
    'windows line endings' => ["NYZ069-070-081200-\r\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\r\n", ['NYZ069', 'NYZ070']],
    'only the first block of the text' => ["ALZ266-080015-\nBaldwin Coastal-\n\n\$\$\n\nALZ265-080015-\n", ['ALZ266']],
    'a code repeated in the line is listed once' => ["FLZ201-201-080015-\n", ['FLZ201']],
    'a name line ending in a dash is not a zone line' => ["Baldwin Coastal-\nMobile Coastal-\n", null],
    'a zone line with no purge time' => ["FLZ201-202-\nBaldwin Coastal-\n", null],
    'text with no zone line' => ["AREA FORECAST DISCUSSION\nNo UGC line here.", null],
]);

test('reads the real bulletins that used to yield no zones', function (string $example, int $count, string $first, string $last) {
    $text = file_get_contents(base_path("database/product_examples/bulletins/{$example}.txt"));

    $codes = (new ParseUgcLine)($text);

    expect($codes)->toHaveCount($count)
        ->and($codes[0])->toBe($first)
        ->and(end($codes))->toBe($last);
})->with([
    'a Non-Precipitation Weather bulletin (AZZ004>018-037>040)' => ['NPW_FGZ', 19, 'AZZ004', 'AZZ040'],
    'the first section of a Marine Weather Message' => ['MWW_MOB', 4, 'GMZ650', 'GMZ675'],
    'the first section of a Hurricane Local Statement' => ['TCV_MOB', 1, 'ALZ266', 'ALZ266'],
]);
