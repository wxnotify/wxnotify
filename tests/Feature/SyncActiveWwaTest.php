<?php

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;

/**
 * Same helper as MatchWeatherAlertRulesTest's createIngestedProductWithoutMatching()
 * -- suppresses IngestedProductObserver so this file's own explicit
 * `(new SyncActiveWwa)(...)` calls are each test's only invocation. Given a
 * different name (not reused from that file) because Pest/PHPUnit loads
 * every Feature test file into one PHP process for a full-suite run, and
 * PHP fatals on a redeclared top-level function -- confirmed directly:
 * running the full suite with both files declaring the same name throws
 * "Cannot redeclare function createIngestedProductWithoutMatching()".
 * Not reusing the other file's copy unqualified was also ruled out: this
 * file needs to run standalone too (`pest tests/Feature/SyncActiveWwaTest.php`),
 * and PHPUnit only loads the file(s) actually passed to it, not the whole
 * Feature/ directory -- so without a local declaration here, a standalone
 * run fails with "Call to undefined function" (confirmed the same way).
 */
function createIngestedProductWithoutMatchingForSync(array $attributes = []): IngestedProduct
{
    return IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create($attributes));
}

test('a text row creates a new active_wwas row and always triggers matching', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'TO.W')->sole();
    $ingested = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'ugc_codes' => ['NYZ069'], 'polygon' => [['lat' => 40.0, 'lon' => -73.0]],
    ]);

    (new SyncActiveWwa)($ingested);

    $wwa = ActiveWwa::sole();
    expect($wwa->vtec_office)->toBe('KOKX')
        ->and($wwa->vtec_event_number)->toBe('0042')
        ->and($wwa->product_id)->toBe($product->id)
        ->and($wwa->ugc_codes)->toBe(['NYZ069'])
        ->and($wwa->source)->toBe('text')
        ->and($wwa->latest_ingested_product_id)->toBe($ingested->id);
    $spy->shouldHaveReceived('__invoke')->once()->with($ingested);
});

test('a continuation text row (no polygon of its own) does not clobber a previously known polygon', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'TO.W')->sole();
    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
    ]);
    DB::statement('UPDATE active_wwas SET polygon = ST_SetSRID(ST_GeomFromText(?), 4269) WHERE id = ?', ['POLYGON((-74 40, -74 41, -73 41, -73 40, -74 40))', $wwa->id]);

    $continuation = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0042', 'vtec_year' => 2026, 'vtec_action' => 'CON',
        'polygon' => null, 'ugc_codes' => ['NYZ069'],
    ]);

    (new SyncActiveWwa)($continuation);

    $stillHasPolygon = DB::selectOne('SELECT polygon IS NOT NULL AS has_polygon FROM active_wwas WHERE id = ?', [$wwa->id]);
    expect($stillHasPolygon->has_polygon)->toBeTrue();
});

test('a CAP row with a matching paired text row enriches active_wwas without re-dispatching', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'FF.W')->sole();
    $textRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'cccc' => 'ktwc', 'ttaaii' => 'wgus55', 'awipsid' => 'ffwtwc', 'wmo_ddhhmm' => '142354',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'received_at' => now(),
    ]);
    (new SyncActiveWwa)($textRow);
    $spy->shouldHaveReceived('__invoke')->once();

    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
        'ugc_codes' => ['AZC021'], 'polygon' => [['lat' => 32.5, 'lon' => -110.5]],
        'received_at' => now()->addSeconds(5),
    ]);

    (new SyncActiveWwa)($capRow);

    expect(ActiveWwa::count())->toBe(1);
    $wwa = ActiveWwa::sole();
    expect($wwa->source)->toBe('cap')
        ->and($wwa->ugc_codes)->toBe(['AZC021'])
        ->and($wwa->latest_ingested_product_id)->toBe($textRow->id);
    $spy->shouldHaveReceived('__invoke')->once(); // still only the one call from the text row
});

test('a CAP row with no matching paired text row dispatches on its own', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'FF.W')->sole();
    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
    ]);

    (new SyncActiveWwa)($capRow);

    $spy->shouldHaveReceived('__invoke')->once()->with($capRow);
    expect(ActiveWwa::sole()->latest_ingested_product_id)->toBe($capRow->id);
});

test('a CAN action resolves active_wwas status to Cancelled', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    $ingested = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0413', 'vtec_year' => 2026, 'vtec_action' => 'CAN',
    ]);

    (new SyncActiveWwa)($ingested);

    expect(ActiveWwa::sole()->status)->toBe('Cancelled');
});

test('a CAP row with a motion description enriches active_wwas motion fields', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0413', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'event_motion_description' => '2026-09-14T23:53:00-00:00...storm...231DEG...34KT...41.31,-96.18',
    ]);

    (new SyncActiveWwa)($capRow);

    $wwa = ActiveWwa::sole();
    expect($wwa->motion_direction_degrees)->toBe(231)
        ->and($wwa->motion_speed_kt)->toBe(34)
        ->and((float) $wwa->motion_reference_lat)->toBe(41.31)
        ->and((float) $wwa->motion_reference_lon)->toBe(-96.18)
        ->and($wwa->motion_observed_at)->not->toBeNull();
});

test('a text row with a real TIME...MOT...LOC line enriches active_wwas motion fields too, not just CAP rows', function () {
    // Real captured text from database/product_examples/SVW.txt -- confirms
    // syncFromText() now writes motion fields, closing the gap where only
    // syncFromCap() ever did, which left the section-picker template
    // builder's Motion section effectively unreachable in the common
    // dispatch path (DeliverWeatherAlert usually fires off the text row,
    // before CAP has a chance to supply motion data).
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    $text = file_get_contents(base_path('database/product_examples/SVW.txt'));
    $textRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text', 'text' => $text,
        'vtec_office' => 'KPAH', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0236', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'received_at' => '2026-09-14 00:52:00',
    ]);

    (new SyncActiveWwa)($textRow);

    $wwa = ActiveWwa::sole();
    expect($wwa->motion_direction_degrees)->toBe(278)
        ->and($wwa->motion_speed_kt)->toBe(15)
        ->and((float) $wwa->motion_reference_lat)->toBe(36.56)
        ->and((float) $wwa->motion_reference_lon)->toBe(-90.73)
        ->and($wwa->motion_observed_at)->not->toBeNull();
});

test('a text continuation with no TIME...MOT...LOC line does not clobber previously known motion fields', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KPAH', 'vtec_phenomenon' => 'SV',
        'vtec_significance' => 'W', 'vtec_event_number' => '0236', 'vtec_year' => 2026,
        'motion_direction_degrees' => 278, 'motion_speed_kt' => 15,
        'motion_reference_lat' => 36.56, 'motion_reference_lon' => -90.73,
    ]);

    $continuation = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'text' => "SEVERE WEATHER STATEMENT\nNo motion line in this continuation.\n",
        'vtec_office' => 'KPAH', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0236', 'vtec_year' => 2026, 'vtec_action' => 'CON',
    ]);

    (new SyncActiveWwa)($continuation);

    $wwa = ActiveWwa::sole();
    expect($wwa->motion_direction_degrees)->toBe(278)
        ->and($wwa->motion_speed_kt)->toBe(15);
});

test('a CAP row that finds its paired text row still sets latest_ingested_product_id to that paired row, even when active_wwas is being created for the first time', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'FF.W')->sole();
    $textRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'cccc' => 'ktwc', 'ttaaii' => 'wgus55', 'awipsid' => 'ffwtwc', 'wmo_ddhhmm' => '142354',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'received_at' => now(),
    ]);
    // Note: no prior sync call for $textRow here -- this specifically covers
    // the case where active_wwas doesn't exist yet and CAP creates it,
    // despite a matching paired text row already existing in ingested_products.
    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
        'received_at' => now()->addSeconds(5),
    ]);

    (new SyncActiveWwa)($capRow);

    expect(ActiveWwa::sole()->latest_ingested_product_id)->toBe($textRow->id);
});

test('a text continuation does not null out a headline previously set by CAP', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    $wwa = ActiveWwa::factory()->create([
        'product_id' => $product->id, 'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV',
        'vtec_significance' => 'W', 'vtec_event_number' => '0413', 'vtec_year' => 2026,
        'headline' => 'A previously CAP-set headline',
    ]);

    $continuation = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0413', 'vtec_year' => 2026, 'vtec_action' => 'CON',
    ]);

    (new SyncActiveWwa)($continuation);

    expect(ActiveWwa::find($wwa->id)->headline)->toBe('A previously CAP-set headline');
});

test('a stale text row outside the pairing time window is ignored, and the CAP row dispatches on its own', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'FF.W')->sole();
    // A text row that superficially matches on awipsid/cccc/ttaaii/wmo_ddhhmm
    // (which only encodes day-hour-minute, no month/year) but is actually
    // from weeks earlier -- this must NOT be treated as this CAP row's pair.
    $staleTextRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'cccc' => 'ktwc', 'ttaaii' => 'wgus55', 'awipsid' => 'ffwtwc', 'wmo_ddhhmm' => '142354',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0100', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'received_at' => now()->subWeeks(3),
    ]);

    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0209', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'WGUS55 KTWC 142354',
        'received_at' => now(),
    ]);

    (new SyncActiveWwa)($capRow);

    // The CAP row must dispatch on its own -- the stale row must not be
    // treated as its pair.
    $spy->shouldHaveReceived('__invoke')->once()->with($capRow);
    expect(ActiveWwa::sole()->latest_ingested_product_id)->toBe($capRow->id)
        ->and(ActiveWwa::sole()->latest_ingested_product_id)->not->toBe($staleTextRow->id);
});

test('a malformed wmo_identifier is treated as no-pair-found, and the CAP row still dispatches', function () {
    // findPairedTextProduct()'s own guard clause (count($parts) !== 3)
    // returns null for a malformed wmo_identifier -- this now runs INSIDE
    // syncFromCap()'s try block (moved there to also guard against the
    // query itself throwing), so this pins down that the guard-clause path
    // still results in a clean $pairedText === null and a real dispatch,
    // not an accidental swallow.
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'FF.W')->sole();
    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'FF', 'vtec_significance' => 'W',
        'vtec_event_number' => '0300', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'FFWTWC', 'wmo_identifier' => 'malformed',
    ]);

    (new SyncActiveWwa)($capRow);

    $spy->shouldHaveReceived('__invoke')->once()->with($capRow);
    expect(ActiveWwa::sole()->latest_ingested_product_id)->toBe($capRow->id);
});

test('a continuation with an all-zero begin/end does not clobber a previously known effective/expires/ugc_codes', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'FL.W')->sole();
    // Establish real values first, from a NEW issuance with a real begin
    // and a real (non-degenerate) end.
    $new = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'FL', 'vtec_significance' => 'W',
        'vtec_event_number' => '0500', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'vtec_begin' => now()->subHour(), 'vtec_end' => now()->addDays(3),
        'ugc_codes' => ['NEC055'],
    ]);
    (new SyncActiveWwa)($new);

    // A continuation with all-zero begin AND end (both nulled by
    // ParseVtecString) and no ugc_codes of its own must not wipe out any
    // of the three fields established above.
    $continuation = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'FL', 'vtec_significance' => 'W',
        'vtec_event_number' => '0500', 'vtec_year' => 2026, 'vtec_action' => 'CON',
        'vtec_begin' => null, 'vtec_end' => null, 'ugc_codes' => null,
    ]);
    (new SyncActiveWwa)($continuation);

    $wwa = ActiveWwa::sole();
    expect($wwa->effective)->not->toBeNull()
        ->and($wwa->expires)->not->toBeNull()
        ->and($wwa->ugc_codes)->toBe(['NEC055']);
});

test('a row whose recorded expires has already passed is demoted to Expired when the row is next touched', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    // Establish a normal, currently-active warning: expires is still in
    // the future at the moment this NEW issuance is synced, so expireIfPast()
    // has nothing to correct yet.
    $original = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0600', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'vtec_begin' => now()->subHour(), 'vtec_end' => now()->addMinutes(30),
    ]);
    (new SyncActiveWwa)($original);
    expect(ActiveWwa::sole()->status)->toBe('Active'); // not yet re-checked

    // Time passes beyond the recorded expires with no explicit EXP product
    // ever issued -- NWS let the warning lapse. Any later touch to the
    // row -- here, a CAP enrichment with no fresh expires of its own --
    // should notice the now-past expires and demote status.
    $this->travel(1)->hour();

    $capEnrichment = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0600', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'vtec_begin' => null, 'vtec_end' => null,
        'awips_identifier' => 'NOMATCH', 'wmo_identifier' => 'NOMATCH NOMATCH 999999',
    ]);
    (new SyncActiveWwa)($capEnrichment);

    expect(ActiveWwa::sole()->status)->toBe('Expired');
});

test('a Cancelled row is not overridden back by the expiry check', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = Product::where('code', 'SV.W')->sole();
    $ingested = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'text',
        'vtec_office' => 'KOAX', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '0700', 'vtec_year' => 2026, 'vtec_action' => 'CAN',
        'vtec_begin' => null, 'vtec_end' => now()->subHour(),
    ]);

    (new SyncActiveWwa)($ingested);

    expect(ActiveWwa::sole()->status)->toBe('Cancelled');
});

test('propagates is_test from the triggering product onto the active_wwas row', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = createIngestedProductWithoutMatchingForSync([
        'format' => 'text',
        'is_test' => true,
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '9001', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'text' => "/O.NEW.KTWC.SV.W.9001.260918T0000Z-260918T0100Z/\n",
    ]);

    (new SyncActiveWwa)($product);

    expect(ActiveWwa::where('vtec_event_number', '9001')->sole()->is_test)->toBeTrue();
});

test('a real (non-test) product leaves is_test false on its active_wwas row', function () {
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = createIngestedProductWithoutMatchingForSync([
        'format' => 'text',
        'is_test' => false,
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '9002', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'text' => "/O.NEW.KTWC.SV.W.9002.260918T0000Z-260918T0100Z/\n",
    ]);

    (new SyncActiveWwa)($product);

    expect(ActiveWwa::where('vtec_event_number', '9002')->sole()->is_test)->toBeFalse();
});

test('a product with is_test left unset (as real ingestion always leaves it) still writes false, not null, onto active_wwas', function () {
    // Regression guard for the NOT-NULL trap: a freshly-created (not
    // ->fresh()'d) IngestedProduct has is_test === null in memory even
    // though the database column defaults to false -- see isTest()'s
    // doc comment on SyncActiveWwa. Unlike the two tests above, this one
    // deliberately does NOT pass is_test at all, mirroring real ingestion
    // (ParseVtecString et al never set it), so it actually exercises the
    // `?? false` coalesce -- reverting that back to a bare $product->is_test
    // would fail this test with a NOT NULL constraint violation swallowed
    // by the try/catch, leaving no active_wwas row at all.
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));
    $product = createIngestedProductWithoutMatchingForSync([
        'format' => 'text',
        'vtec_office' => 'KTWC', 'vtec_phenomenon' => 'SV', 'vtec_significance' => 'W',
        'vtec_event_number' => '9003', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'text' => "/O.NEW.KTWC.SV.W.9003.260918T0000Z-260918T0100Z/\n",
    ]);
    expect($product->is_test)->toBeNull();

    (new SyncActiveWwa)($product);

    expect(ActiveWwa::where('vtec_event_number', '9003')->sole()->is_test)->toBeFalse();
});

test('a failure syncing active_wwas does not prevent MatchWeatherAlertRules from still being called', function () {
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);
    $product = Product::where('code', 'TO.W')->sole();
    // A polygon with a NaN-producing/invalid shape isn't easy to construct via
    // valid PHP floats, so instead force the failure via an out-of-range
    // motion value on a CAP row with no paired text (still must dispatch).
    $capRow = createIngestedProductWithoutMatchingForSync([
        'product_id' => $product->id, 'format' => 'cap',
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W',
        'vtec_event_number' => '0099', 'vtec_year' => 2026, 'vtec_action' => 'NEW',
        'awips_identifier' => 'NOTHING', 'wmo_identifier' => 'ZZZZ99 KOKX 999999',
        'event_motion_description' => '2026-09-14T23:53:00-00:00...storm...999999DEG...34KT...41.31,-96.18',
    ]);

    (new SyncActiveWwa)($capRow);

    $spy->shouldHaveReceived('__invoke')->once()->with($capRow);
});
