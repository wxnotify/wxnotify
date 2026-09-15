<?php

use App\Actions\Pipeline\ParseVtecString;
use Illuminate\Support\Carbon;

test('parses every component of a NEW VTEC string with a real begin time', function () {
    $result = (new ParseVtecString)('/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/');

    expect($result)->toEqual([
        'office' => 'KOKX',
        'phenomenon' => 'TO',
        'significance' => 'W',
        'event_number' => '0042',
        'year' => 2026,
        'action' => 'NEW',
        'begin' => Carbon::parse('2026-09-06T18:00:00Z'),
        'end' => Carbon::parse('2026-09-06T19:00:00Z'),
    ]);
});

test('an all-zero begin time (already in effect) parses to a null begin, not epoch zero', function () {
    $result = (new ParseVtecString)('/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/');

    expect($result['begin'])->toBeNull()
        ->and($result['end'])->toEqual(Carbon::parse('2026-09-15T00:15:00Z'))
        ->and($result['action'])->toBe('CAN');
});

test('the year is derived from the end timestamp, not hardcoded or guessed', function () {
    $result = (new ParseVtecString)('/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/');

    expect($result['year'])->toBe(2026);
});

test('returns null when the text contains no VTEC string at all', function () {
    $result = (new ParseVtecString)('AREA FORECAST DISCUSSION with no VTEC line here.');

    expect($result)->toBeNull();
});

test('finds the VTEC string embedded among other text', function () {
    $text = "WFUS51 KOKX 061800\nTORKOKX\n\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\nTornado Warning text follows.";

    $result = (new ParseVtecString)($text);

    expect($result['office'])->toBe('KOKX')
        ->and($result['event_number'])->toBe('0042');
});

test('a text with two VTEC strings resolves the first (leftmost) one', function () {
    $text = <<<'TEXT'
    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    /O.CAN.KOKX.SV.W.0041.000000T0000Z-260906T1800Z/
    TEXT;

    $result = (new ParseVtecString)($text);

    expect($result['phenomenon'])->toBe('TO')
        ->and($result['significance'])->toBe('W')
        ->and($result['event_number'])->toBe('0042')
        ->and($result['action'])->toBe('NEW');
});
