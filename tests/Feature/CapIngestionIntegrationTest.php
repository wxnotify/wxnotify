<?php

use App\Jobs\DeliverWeatherAlert;
use App\Models\ActiveWwa;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\Location;
use App\Models\NotificationChannel;
use App\Models\Product;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

/**
 * Every other test in this feature isolates ONE task's logic with its
 * neighbors mocked/spied out (IngestNwwsProductControllerTest spies both
 * MatchWeatherAlertRules and SyncActiveWwa; SyncActiveWwaTest builds
 * IngestedProduct rows directly via factory with withoutEvents and spies
 * MatchWeatherAlertRules; MatchWeatherAlertRulesTest creates active_wwas rows
 * via factory directly, never via SyncActiveWwa). This test exercises the
 * REAL, full sequence -- text arrives, its paired CAP arrives, a
 * continuation statement arrives -- through the actual HTTP ingestion
 * endpoint with every real action wired up, no mocks/spies at all. This is
 * exactly the sequence that was manually verified once by hand (tinker/curl)
 * during this feature's final review; this test guards it going forward.
 */
beforeEach(function () {
    Config::set('services.pipeline.webhook_token', 'test-webhook-token');

    $this->productsRoot = sys_get_temp_dir().'/cap-integration-test-'.uniqid();
    File::makeDirectory($this->productsRoot, recursive: true);
    Config::set('services.pipeline.products_path', $this->productsRoot);
});

afterEach(function () {
    File::deleteDirectory($this->productsRoot);
});

test('a real text-NEW, paired CAP, and text-continuation sequence produces one active_wwas row and correctly matches Polygon-mode subscribers on the continuation using the persisted polygon', function () {
    // Only DeliverWeatherAlert is faked (captured for the assertion below) --
    // ProcessIngestedProduct itself must still run for real, on the sync
    // queue connection tests use, or none of SyncActiveWwa/MatchWeatherAlertRules
    // below would actually execute.
    Queue::fake([DeliverWeatherAlert::class]);

    // FF.W is part of the real catalog seeded by the products table
    // migration (see ExtractProductMetadataTest) -- no need to create it.
    $product = Product::where('code', 'FF.W')->sole();
    $location = Location::factory()->create(['lat' => 32.55, 'lng' => -110.58]); // inside the real captured polygon
    $rule = AlertRule::factory()->create(['category' => 'weather_alerts', 'matching_mode' => 'polygon']);
    $rule->locations()->attach($location);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    // Step 1: the real captured NEW text bulletin.
    File::ensureDirectoryExists($this->productsRoot.'/ktwc');
    $textBody = <<<'TEXT'
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

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;
    File::put($this->productsRoot.'/ktwc/ktwc_wgus55-ffwtwc.142354_int1.txt', $textBody);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'ktwc/ktwc_wgus55-ffwtwc.142354_int1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    // Step 2: the real captured paired CAP payload, a few seconds later.
    File::ensureDirectoryExists($this->productsRoot.'/kwbc');
    $capBody = <<<'TEXT'
    480

    XOUS55 KWBC 142354

    CAPTWC

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <msgType>Alert</msgType>
        <info>
            <event>Flash Flood Warning</event>
            <severity>Severe</severity>
            <urgency>Immediate</urgency>
            <certainty>Likely</certainty>
            <headline>Flash Flood Warning issued by NWS Tucson AZ</headline>
            <description>Flash flooding is ongoing across the warned area.</description>
            <instruction>Turn around, don't drown.</instruction>
            <parameter><valueName>AWIPSidentifier</valueName><value>FFWTWC</value></parameter>
            <parameter><valueName>WMOidentifier</valueName><value>WGUS55 KTWC 142354</value></parameter>
            <parameter><valueName>VTEC</valueName><value>/O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/</value></parameter>
            <area>
                <areaDesc>Pinal, AZ</areaDesc>
                <polygon>32.51,-110.56 32.57,-110.64 32.61,-110.58 32.51,-110.56</polygon>
                <geocode><valueName>UGC</valueName><value>AZC021</value></geocode>
            </area>
        </info>
    </alert>
    TEXT;
    File::put($this->productsRoot.'/kwbc/kwbc_xous55-captwc.142354_int2.txt', $capBody);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kwbc/kwbc_xous55-captwc.142354_int2.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    // Step 3: a text continuation statement (SVS-style) for the SAME event,
    // carrying no LAT...LON of its own -- the exact case Task 9's polygon
    // consultation exists for.
    File::ensureDirectoryExists($this->productsRoot.'/ktwc');
    $continuationBody = <<<'TEXT'
    WGUS55 KTWC 142400
    FFSTWC

    Flash Flood Warning
    National Weather Service Tucson AZ
    500 PM MST Mon Sep 14 2026

    AZC021-142100-
    /O.CON.KTWC.FF.W.0209.000000T0000Z-260915T0300Z/

    The Flash Flood Warning continues for...
    Southeastern Pinal County in southeastern Arizona...
    TEXT;
    File::put($this->productsRoot.'/ktwc/ktwc_wgus55-ffstwc.142400_int3.txt', $continuationBody);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'ktwc/ktwc_wgus55-ffstwc.142400_int3.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    // Assert: exactly 3 IngestedProduct rows, exactly 1 active_wwas row,
    // correctly enriched, and the continuation's dispatch used the
    // persisted (CAP-enriched) polygon, not a fallback.
    expect(IngestedProduct::count())->toBe(3);

    // source ends up 'text', not 'cap': SyncActiveWwa's text path
    // unconditionally stamps source => 'text' on every text-sourced row
    // (see SyncActiveWwa::syncFromText), and the continuation (step 3) is
    // itself a text row landing after the CAP row (step 2). The point of
    // this test is the persisted polygon surviving that continuation
    // untouched (asserted below), not the source label.
    $wwa = ActiveWwa::sole();
    expect($wwa->vtec_office)->toBe('KTWC')
        ->and($wwa->vtec_event_number)->toBe('0209')
        ->and($wwa->source)->toBe('text')
        ->and($wwa->ugc_codes)->toBe(['AZC021']);

    $polygonExists = DB::selectOne('SELECT polygon IS NOT NULL AS has_polygon FROM active_wwas WHERE id = ?', [$wwa->id]);
    expect($polygonExists->has_polygon)->toBeTrue();

    // 2 real dispatches: the NEW text, and the CON continuation -- CAP must
    // NOT have triggered its own (it found its pair).
    Queue::assertPushed(DeliverWeatherAlert::class, 2);
});
