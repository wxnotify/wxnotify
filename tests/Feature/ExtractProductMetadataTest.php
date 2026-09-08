<?php

use App\Actions\Pipeline\ExtractProductMetadata;
use App\Models\Product;

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
