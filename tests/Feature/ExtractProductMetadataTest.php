<?php

use App\Actions\Pipeline\ExtractProductMetadata;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('a VTEC-tagged product resolves product_id from its phenomena and significance codes', function () {
    // TO.W is part of the real catalog seeded by the products table migration,
    // so it's fetched rather than created via factory (which would collide on
    // the unique `code` constraint) — see ProductCatalogSeederTest for the
    // established convention.
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $text = <<<'TEXT'
    WFUS51 KOKX 061800
    TORKOKX

    BULLETIN - EAS ACTIVATION REQUESTED
    Tornado Warning
    National Weather Service New York NY
    200 PM EDT Sun Sep 6 2026

    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'torokx');

    expect($metadata['product_id'])->toBe($tornadoWarning->id);
});

test('a product with two VTEC lines resolves product_id from the first one', function () {
    // Both TO.W and SV.W already exist in the seeded catalog (see note above);
    // no need to create SV.W, it just needs to already be resolvable. TO.W's
    // line comes first in the text on purpose -- "first match wins" is a
    // literal textual-order rule (see the design doc: action codes like CAN
    // are NOT filtered out), so the fixture's physical line order is what
    // this test is actually exercising.
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $text = <<<'TEXT'
    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    /O.CAN.KOKX.SV.W.0041.000000T0000Z-260906T1800Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['product_id'])->toBe($tornadoWarning->id);
});

test('an AFD product resolves product_id via its AWIPS-ID prefix, not VTEC', function () {
    // AFD is part of the real seeded catalog (see note above).
    $afd = Product::where('code', 'AFD')->sole();
    $text = "AREA FORECAST DISCUSSION\nNational Weather Service New York NY\n200 PM EDT Sun Sep 6 2026";

    $metadata = (new ExtractProductMetadata)($text, 'afdokx');

    expect($metadata['product_id'])->toBe($afd->id);
});

test('a product with neither a VTEC line nor a confirmed AWIPS-ID prefix resolves no product_id', function () {
    // e.g. real RWR* river/water-resources chatter, confirmed abundant in
    // live NWWS traffic and correctly outside the product catalog.
    $metadata = (new ExtractProductMetadata)('RIVER STAGE DATA...', 'rwrokx');

    expect($metadata['product_id'])->toBeNull();
});

test('a VTEC phenomena+significance with no matching catalog product resolves no product_id', function () {
    $text = '/O.NEW.KOKX.ZZ.Q.0001.260906T1800Z-260906T1900Z/';

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['product_id'])->toBeNull();
});

test('a UGC line with a range and a repeated-prefix code expands into individual zone codes', function () {
    $text = <<<'TEXT'
    NYZ069-070>075-081200-
    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toBe(['NYZ069', 'NYZ070', 'NYZ071', 'NYZ072', 'NYZ073', 'NYZ074', 'NYZ075']);
});

test('a UGC line with plain bare-digit continuations (no range) parses each one under the current prefix', function () {
    $text = "NYZ069-070-071-081200-\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toBe(['NYZ069', 'NYZ070', 'NYZ071']);
});

test('a UGC line mixing a county code and a zone code with a shared purge time parses both', function () {
    $text = "NYC061-NJZ103-081200-\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['ugc_codes'])->toBe(['NYC061', 'NJZ103']);
});

test('a product with no UGC line resolves no ugc_codes', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo UGC line here.", null);

    expect($metadata['ugc_codes'])->toBeNull();
});

test('a LAT...LON polygon line decodes 4-digit coordinate pairs into real lat/lon', function () {
    $text = <<<'TEXT'
    LAT...LON 3982 8066 3989 8064 3992 8047 3978 8050
    TIME...MOT...LOC 1800Z 123 456
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toBe([
        ['lat' => 39.82, 'lon' => -80.66],
        ['lat' => 39.89, 'lon' => -80.64],
        ['lat' => 39.92, 'lon' => -80.47],
        ['lat' => 39.78, 'lon' => -80.50],
    ]);
});

test('a LAT...LON line wrapping onto a continuation line is fully collected', function () {
    $text = <<<'TEXT'
    LAT...LON 3982 8066 3989 8064
    3992 8047 3978 8050

    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toHaveCount(4);
});

test('a 5-digit coordinate part decodes with the decimal after 3 digits, for longitudes past 100 degrees', function () {
    $text = "LAT...LON 4046 10066 4050 10070 4048 10040\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'][0])->toBe(['lat' => 40.46, 'lon' => -100.66]);
});

test('a non-digit-leading continuation line inside the polygon block is skipped, not scanned for stray digits', function () {
    // Regression: preg_match_all() against the whole trimmed line would
    // have spliced digits from "SEE WARNING 1234 ISSUED 5678" into the
    // coordinate list as a fabricated vertex. The legacy reference only
    // ever collects lines that actually start with a digit.
    $text = <<<'TEXT'
    LAT...LON 3982 8066 3989 8064
    SEE WARNING 1234 ISSUED 5678
    3992 8047 3978 8050

    TEXT;

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toBe([
        ['lat' => 39.82, 'lon' => -80.66],
        ['lat' => 39.89, 'lon' => -80.64],
        ['lat' => 39.92, 'lon' => -80.47],
        ['lat' => 39.78, 'lon' => -80.50],
    ]);
});

test('a concatenated 8-digit LAT...LON token (SPC MD/WPC MPD format) decodes correctly', function () {
    $text = file_get_contents(base_path('database/product_examples/SWO.txt'));

    $metadata = (new ExtractProductMetadata)($text, 'swomcd');

    expect($metadata['polygon'])->toBe([
        ['lat' => 33.31, 'lon' => -114.29],
        ['lat' => 34.15, 'lon' => -114.73],
        ['lat' => 34.54, 'lon' => -114.89],
        ['lat' => 35.2, 'lon' => -115.15],
        ['lat' => 35.55, 'lon' => -114.99],
        ['lat' => 35.32, 'lon' => -113.91],
        ['lat' => 35.08, 'lon' => -113.1],
        ['lat' => 34.62, 'lon' => -112.2],
        ['lat' => 34.34, 'lon' => -111.41],
        ['lat' => 33.81, 'lon' => -110.53],
        ['lat' => 32.73, 'lon' => -109.67],
        ['lat' => 32.47, 'lon' => -109.95],
        ['lat' => 32.39, 'lon' => -110.71],
        ['lat' => 32.5, 'lon' => -111.98],
        ['lat' => 33.05, 'lon' => -113.99],
        ['lat' => 33.31, 'lon' => -114.29],
    ]);
});

test('many individually-4-digit separate tokens are never misclassified as combined-format, regardless of total count', function () {
    // Distinct from the "decodes 4-digit coordinate pairs" test above: that
    // test only has 4 tokens (2 points). This one has 16 tokens (8 points)
    // -- none individually >= 8 chars -- to prove $isCombinedFormat's
    // per-token length check isn't fooled by a large total token/digit
    // count and doesn't accidentally route a legitimate separate-token line
    // through the combined-token decode path.
    $text = "LAT...LON 3650 9082 3666 9079 3663 9055 3650 9058 3644 9061 3658 9070 3672 9065 3655 9048\n";

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['polygon'])->toBe([
        ['lat' => 36.5, 'lon' => -90.82],
        ['lat' => 36.66, 'lon' => -90.79],
        ['lat' => 36.63, 'lon' => -90.55],
        ['lat' => 36.5, 'lon' => -90.58],
        ['lat' => 36.44, 'lon' => -90.61],
        ['lat' => 36.58, 'lon' => -90.7],
        ['lat' => 36.72, 'lon' => -90.65],
        ['lat' => 36.55, 'lon' => -90.48],
    ]);
});

test('a product with no LAT...LON line resolves no polygon', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo polygon here.", null);

    expect($metadata['polygon'])->toBeNull();
});

test('a Day 1 Convective Outlook resolves product_id via its exact AWIPS id, not a 3-letter prefix', function () {
    // SPC.OUTLOOK is part of the real seeded catalog (see note above).
    // "swo" is shared by every SPC convective-outlook/mesoscale-discussion
    // product (swody1/swody2/swody3/swod48/swomcd), so this only resolves
    // correctly if the full id is matched, not a 3-letter prefix like AFD's.
    $outlook = Product::where('code', 'SPC.OUTLOOK')->sole();
    $text = "SPC AC 140059\n\nDay 1 Convective Outlook\nNWS Storm Prediction Center Norman OK";

    $metadata = (new ExtractProductMetadata)($text, 'swody1');

    expect($metadata['product_id'])->toBe($outlook->id);
});

test('a Day 2 Convective Outlook resolves to the distinct DY2 product, not SPC.OUTLOOK', function () {
    // DY2 isn't part of the base seeded catalog (only ProductCatalogSeeder
    // adds it, which tests don't run) -- created directly instead of
    // fetched, per this suite's convention for non-guaranteed products.
    $dayTwo = Product::factory()->create(['code' => 'DY2']);

    $metadata = (new ExtractProductMetadata)('Day 2 Convective Outlook', 'swody2');

    expect($metadata['product_id'])->toBe($dayTwo->id);
});

test('a Day 1 Convective Outlook with one categorical risk statement extracts that risk level', function () {
    $text = <<<'TEXT'
    ...SUMMARY...
    ...THERE IS A MODERATE RISK OF SEVERE THUNDERSTORMS FOR SOUTHWEST AND CENTRAL KANSAS INTO FAR NORTHWEST OKLAHOMA...
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'swody1');

    expect($metadata['risk_levels'])->toBe(['Moderate']);
});

test('a Day 1 Convective Outlook stating two different risk levels extracts both, deduplicated', function () {
    $text = <<<'TEXT'
    ...THERE IS A MODERATE RISK OF SEVERE THUNDERSTORMS FOR SOUTHWEST AND CENTRAL KANSAS...
    ...THERE IS A SLIGHT RISK OF SEVERE THUNDERSTORMS FROM EASTERN MISSISSIPPI...CENTRAL ALABAMA...
    ...THERE IS A SLIGHT RISK OF SEVERE THUNDERSTORMS ACROSS THE SAME REGION LATER TODAY...
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'swody1');

    expect($metadata['risk_levels'])->toBe(['Moderate', 'Slight']);
});

test('a Day 1 Convective Outlook with no risk statement extracts an empty (not null) risk_levels array', function () {
    $text = "...NO SEVERE THUNDERSTORM AREAS FORECAST...\n\n...SUMMARY...\nThe organized-severe threat appears low.";

    $metadata = (new ExtractProductMetadata)($text, 'swody1');

    expect($metadata['risk_levels'])->toBe([]);
});

test('a product other than the Day 1 Convective Outlook never attempts risk-level extraction', function () {
    // The Fire Weather Outlook (SPC.FIRE) also has risk_level_options in the
    // catalog, but its text format isn't a confirmed fixed sentence, so
    // extraction is deliberately not attempted for it -- null, not [].
    $text = 'THERE IS A MODERATE RISK OF SEVERE THUNDERSTORMS mentioned incidentally';

    $metadata = (new ExtractProductMetadata)($text, 'fwdy1');

    expect($metadata['risk_levels'])->toBeNull();
});

test('a VTEC-tagged product also populates the shared vtec_* fields', function () {
    Carbon::setTestNow('2026-09-06T18:30:00Z');

    $text = <<<'TEXT'
    WFUS51 KOKX 061800
    TORKOKX

    /O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'torokx');

    expect($metadata['vtec_office'])->toBe('KOKX')
        ->and($metadata['vtec_phenomenon'])->toBe('TO')
        ->and($metadata['vtec_significance'])->toBe('W')
        ->and($metadata['vtec_event_number'])->toBe('0042')
        ->and($metadata['vtec_year'])->toBe(2026)
        ->and($metadata['vtec_action'])->toBe('NEW')
        ->and($metadata['vtec_begin'])->not->toBeNull()
        ->and($metadata['vtec_end'])->not->toBeNull();
});

test('a product with no VTEC line leaves every vtec_* field null', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo VTEC line here.", 'afdokx');

    expect($metadata['vtec_office'])->toBeNull()
        ->and($metadata['vtec_phenomenon'])->toBeNull()
        ->and($metadata['vtec_significance'])->toBeNull()
        ->and($metadata['vtec_event_number'])->toBeNull()
        ->and($metadata['vtec_year'])->toBeNull()
        ->and($metadata['vtec_action'])->toBeNull()
        ->and($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_end'])->toBeNull();
});

test('an all-zero VTEC begin time resolves to a null vtec_begin', function () {
    $text = '/O.CAN.KOAX.SV.W.0413.000000T0000Z-260915T0015Z/';

    $metadata = (new ExtractProductMetadata)($text, null);

    expect($metadata['vtec_begin'])->toBeNull()
        ->and($metadata['vtec_action'])->toBe('CAN');
});

test('populates the narrative section fields from a real VTEC warning bulletin', function () {
    $text = <<<'TEXT'
    WGUS55 KTWC 142354
    FFWTWC

    BULLETIN - EAS ACTIVATION REQUESTED
    Flash Flood Warning
    National Weather Service Tucson AZ
    454 PM MST Mon Sep 14 2026

    AZC021-142100-
    /O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;

    $metadata = (new ExtractProductMetadata)($text, 'ffwtwc');

    expect($metadata['hazard'])->toBe('Flash flooding caused by thunderstorms.')
        ->and($metadata['hazard_source'])->toBe('Radar indicated.')
        ->and($metadata['impact'])->toBe('Flash flooding of small creeks and streams.')
        ->and($metadata['locations_impacted'])->toBeNull()
        ->and($metadata['call_to_action'])->toBeNull();
});

test('a product with no narrative sections leaves all five fields null', function () {
    $metadata = (new ExtractProductMetadata)("AREA FORECAST DISCUSSION\nNo labeled sections here.", 'afdokx');

    expect($metadata['hazard'])->toBeNull()
        ->and($metadata['hazard_source'])->toBeNull()
        ->and($metadata['impact'])->toBeNull()
        ->and($metadata['locations_impacted'])->toBeNull()
        ->and($metadata['call_to_action'])->toBeNull();
});
