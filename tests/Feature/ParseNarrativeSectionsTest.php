<?php

use App\Actions\Pipeline\ParseNarrativeSections;

/**
 * A real, verbatim Flash Flood Warning bulletin body -- the same fixture
 * used throughout docs/superpowers/plans/2026-09-14-active-wwa-tracking.md,
 * confirmed against live captured NWWS traffic. Has HAZARD/SOURCE/IMPACT
 * bullets but no "locations impacted" line and no
 * PRECAUTIONARY/PREPAREDNESS ACTIONS section.
 */
function realFfwBulletinText(): string
{
    return <<<'TEXT'
    WGUS55 KTWC 142354
    FFWTWC

    BULLETIN - EAS ACTIVATION REQUESTED
    Flash Flood Warning
    National Weather Service Tucson AZ
    454 PM MST Mon Sep 14 2026

    AZC021-142100-
    /O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/

    The National Weather Service in Tucson has issued a

    * Flash Flood Warning for...
    Southeastern Pinal County in southeastern Arizona...

    * Until 800 PM MST.

    * At 454 PM MST, Doppler radar indicated thunderstorms producing
    heavy rain across the warned area. Flash flooding is ongoing or
    expected to begin shortly.

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams, urban areas,
    highways, streets and underpasses as well as other poor
    drainage and low-lying areas.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;
}

/**
 * NOT a verbatim historical capture -- a realistic construction following
 * standard, well-documented NWS severe-weather-warning bulletin
 * conventions (the PRECAUTIONARY/PREPAREDNESS ACTIONS.../&&/LAT...LON
 * structure is independently confirmed elsewhere in this codebase, e.g.
 * ExtractProductMetadata::parsePolygon()'s own docblock and fixtures).
 * Used specifically to exercise the locations_impacted/call_to_action
 * paths, which realFfwBulletinText() above doesn't contain.
 */
function realisticSevereThunderstormBulletinText(): string
{
    return <<<'TEXT'
    HAZARD...60 mph wind gusts and quarter size hail.

    SOURCE...Radar indicated.

    IMPACT...Expect damage to roofs, siding, and trees.

    Locations impacted include...
    Springfield, Right City and Millbrook.

    PRECAUTIONARY/PREPAREDNESS ACTIONS...

    For your protection move to an interior room on the lowest floor of a
    building.

    &&

    LAT...LON 4046 10066 4050 10070 4048 10040
    TEXT;
}

test('extracts hazard, source, and impact from a real bulletin, normalized to single lines', function () {
    $result = (new ParseNarrativeSections)(realFfwBulletinText());

    expect($result['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($result['hazard_source'])->toBe('Radar indicated.')
        ->and($result['impact'])->toBe('Flash flooding of small creeks and streams, urban areas, highways, streets and underpasses as well as other poor drainage and low-lying areas.');
});

test('a bulletin with no locations-impacted or call-to-action section returns null for both, not a wrong guess', function () {
    $result = (new ParseNarrativeSections)(realFfwBulletinText());

    expect($result['locations_impacted'])->toBeNull()
        ->and($result['call_to_action'])->toBeNull();
});

test('extracts locations_impacted and call_to_action, stopping at the && delimiter', function () {
    $result = (new ParseNarrativeSections)(realisticSevereThunderstormBulletinText());

    expect($result['locations_impacted'])->toBe('Springfield, Right City and Millbrook.')
        ->and($result['call_to_action'])->toBe('For your protection move to an interior room on the lowest floor of a building.');
});

test('a blank line immediately after a label does not produce an empty result', function () {
    // PRECAUTIONARY/PREPAREDNESS ACTIONS... conventionally has a blank line
    // before its body starts, unlike HAZARD/SOURCE/IMPACT which put content
    // on the same "paragraph". A naive "stop at the first blank line" parser
    // would incorrectly capture nothing here.
    $result = (new ParseNarrativeSections)(realisticSevereThunderstormBulletinText());

    expect($result['call_to_action'])->not->toBeNull()
        ->and($result['call_to_action'])->not->toBe('');
});

test('a product with no VTEC narrative structure at all returns all five fields as null', function () {
    $result = (new ParseNarrativeSections)("AREA FORECAST DISCUSSION\nJust ordinary prose, no labeled sections.");

    expect($result)->toBe([
        'hazard' => null,
        'hazard_source' => null,
        'impact' => null,
        'locations_impacted' => null,
        'call_to_action' => null,
    ]);
});

test('an empty string input returns all five fields as null', function () {
    $result = (new ParseNarrativeSections)('');

    expect($result)->toBe([
        'hazard' => null,
        'hazard_source' => null,
        'impact' => null,
        'locations_impacted' => null,
        'call_to_action' => null,
    ]);
});
