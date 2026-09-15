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
