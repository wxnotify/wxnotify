<?php

use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Jobs\ProcessIngestedProduct;
use App\Jobs\SendHeartbeatPing;
use App\Models\IngestedProduct;
use App\Models\PipelineDelivery;
use App\Models\Product;
use App\Settings\IntegrationSettings;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Config::set('services.pipeline.webhook_token', 'test-webhook-token');

    // A real temp directory standing in for the shared Docker volume — the
    // controller must never assume /products exists or is writable in a
    // test environment.
    $this->productsRoot = sys_get_temp_dir().'/pipeline-products-test-'.uniqid();
    File::makeDirectory($this->productsRoot.'/kkci', recursive: true);
    Config::set('services.pipeline.products_path', $this->productsRoot);
});

afterEach(function () {
    File::deleteDirectory($this->productsRoot);
});

test('rejects a request with no bearer token', function () {
    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'])
        ->assertUnauthorized();

    expect(IngestedProduct::count())->toBe(0);
});

test('rejects a request with the wrong bearer token', function () {
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer wrong-token'],
    )->assertUnauthorized();

    expect(IngestedProduct::count())->toBe(0);
});

test('ingests a well-formed product and decodes its filename', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'TAF KORD 221432Z sample text');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->cccc)->toBe('kkci')
        ->and($product->ttaaii)->toBe('ftus21')
        ->and($product->awipsid)->toBe('tafkord')
        ->and($product->wmo_ddhhmm)->toBe('221432')
        ->and($product->nwws_id)->toBe('12345')
        ->and($product->text)->toBe('TAF KORD 221432Z sample text')
        ->and($product->file_path)->toBe('kkci/kkci_ftus21-tafkord.221432_12345.txt')
        ->and($product->received_at)->not->toBeNull();
});

test('still ingests a product with an unparseable filename, with metadata fields left null', function () {
    File::put($this->productsRoot.'/kkci/not-a-valid-filename.txt', 'some text');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/not-a-valid-filename.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->cccc)->toBeNull()
        ->and($product->ttaaii)->toBeNull()
        ->and($product->text)->toBe('some text');
});

test('returns 404 for a path that does not exist on disk', function () {
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/does-not-exist.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNotFound();

    expect(IngestedProduct::count())->toBe(0);
});

test('rejects a path that escapes the configured products root', function () {
    File::put($this->productsRoot.'/../outside.txt', 'should not be readable');

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => '../outside.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNotFound();

    expect(IngestedProduct::count())->toBe(0);

    File::delete($this->productsRoot.'/../outside.txt');
});

test('is idempotent for the same path', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'original text');
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', 'updated text');
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(IngestedProduct::count())->toBe(1)
        ->and(IngestedProduct::sole()->text)->toBe('updated text');
});

test('ingesting a VTEC product enriches product_id/ugc_codes/polygon before the row is written', function () {
    // TO.W is part of the real catalog seeded by the products table
    // migration (see ExtractProductMetadataTest) — no need to create it.
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    File::ensureDirectoryExists($this->productsRoot.'/kokx');
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\nLAT...LON 3982 8066 3989 8064 3992 8047\n";
    File::put($this->productsRoot.'/kokx/kokx_wfus51-torokx.061800_1.txt', $text);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kokx/kokx_wfus51-torokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->product_id)->toBe($tornadoWarning->id)
        ->and($product->ugc_codes)->toBe(['NYZ069'])
        ->and($product->polygon)->toHaveCount(3);
});

test('ingesting a VTEC product with narrative bullets persists the five hazard/impact fields through updateOrCreate', function () {
    // Guards against a regression at the #[Fillable] boundary specifically:
    // ExtractProductMetadataTest and ParseNarrativeSectionsTest both prove
    // the extraction logic is correct in isolation, but neither of them
    // proves the resulting array keys actually survive IngestedProduct's
    // real updateOrCreate() write — a typo or omission in Fillable would
    // silently drop these fields and nothing else in the suite would catch
    // it, since every other narrative test inspects the extractor's
    // returned array directly rather than a persisted row.
    Product::where('code', 'FF.W')->sole();
    File::ensureDirectoryExists($this->productsRoot.'/ktwc');
    $text = <<<'TEXT'
    AZC021-142100-
    /O.NEW.KTWC.FF.W.0209.260914T2354Z-260915T0300Z/

    HAZARD...Flash flooding caused by thunderstorms.

    SOURCE...Radar indicated.

    IMPACT...Flash flooding of small creeks and streams.

    LAT...LON 3251 11056 3257 11064 3261 11058 3251 11056
    TEXT;
    File::put($this->productsRoot.'/ktwc/ktwc_wgus55-ffwtwc.142354_1.txt', $text);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'ktwc/ktwc_wgus55-ffwtwc.142354_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->hazard)->toBe('Flash flooding caused by thunderstorms.')
        ->and($product->hazard_source)->toBe('Radar indicated.')
        ->and($product->impact)->toBe('Flash flooding of small creeks and streams.')
        ->and($product->locations_impacted)->toBeNull()
        ->and($product->call_to_action)->toBeNull();
});

test('a newly ingested product dispatches MatchWeatherAlertRules exactly once', function () {
    // MatchWeatherAlertRules itself is synchronous (see Task 8) — asserting
    // its effect here would require real AlertRule fixtures, which belongs
    // in MatchWeatherAlertRulesTest. This test only proves the observer
    // wiring fires on create — not on the later `updateOrCreate` update
    // path — by spying on the action itself.
    $spy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $spy);

    File::ensureDirectoryExists($this->productsRoot.'/okx');
    $text = "AREA FORECAST DISCUSSION\n";
    File::put($this->productsRoot.'/okx/okx_fxus61-afdokx.061800_1.txt', $text);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'okx/okx_fxus61-afdokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $spy->shouldHaveReceived('__invoke')->once();

    // Re-posting the same path hits updateOrCreate's update branch, not
    // create — confirms the observer is bound to `created`, not a generic
    // save hook, matching the design's "only fires once per real product".
    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'okx/okx_fxus61-afdokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $spy->shouldHaveReceived('__invoke')->once();
});

test('a newly ingested product dispatches ProcessIngestedProduct onto the queue, not inline', function () {
    Bus::fake();

    File::ensureDirectoryExists($this->productsRoot.'/okx');
    File::put($this->productsRoot.'/okx/okx_fxus61-afdokx.061800_1.txt', "AREA FORECAST DISCUSSION\n");

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'okx/okx_fxus61-afdokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    Bus::assertDispatched(ProcessIngestedProduct::class, fn (ProcessIngestedProduct $job) => $job->ingestedProductId === $product->id);
});

test('ingesting a CAP product runs the CAP extractor and persists its fields', function () {
    // FF.W is part of the real catalog seeded by the products table
    // migration (see ExtractProductMetadataTest) — no need to create it.
    Product::where('code', 'FF.W')->sole();
    File::ensureDirectoryExists($this->productsRoot.'/kwbc');
    $body = <<<'TEXT'
    480

    XOUS55 KWBC 142354

    CAPTWC

    <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
    <alert xmlns="urn:oasis:names:tc:emergency:cap:1.2">
        <msgType>Alert</msgType>
        <info>
            <event>Flash Flood Warning</event>
            <severity>Severe</severity>
            <description>Flash flooding text.</description>
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
    File::put($this->productsRoot.'/kwbc/kwbc_xous55-captwc.142354_1.txt', $body);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kwbc/kwbc_xous55-captwc.142354_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $product = IngestedProduct::sole();

    expect($product->format)->toBe('cap')
        ->and($product->event)->toBe('Flash Flood Warning')
        ->and($product->awips_identifier)->toBe('FFWTWC')
        ->and($product->vtec_office)->toBe('KTWC')
        ->and($product->ugc_codes)->toBe(['AZC021']);
});

test('ingesting a plain text product still runs the text extractor, not the CAP one', function () {
    File::ensureDirectoryExists($this->productsRoot.'/okx');
    File::put($this->productsRoot.'/okx/okx_fxus61-afdokx.061800_1.txt', "AREA FORECAST DISCUSSION\n");

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'okx/okx_fxus61-afdokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(IngestedProduct::sole()->format)->toBe('text');
});

test('ingesting a malformed CAP product logs the failure and does not create a row, rather than 500ing', function () {
    File::ensureDirectoryExists($this->productsRoot.'/kwbc');
    File::put($this->productsRoot.'/kwbc/kwbc_xous55-captwc.142354_2.txt', "480\n\nXOUS55 KWBC 142354\n\nCAPTWC\n\n<?xml version=\"1.0\"?><alert><unclosed>\n");

    Log::spy();

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kwbc/kwbc_xous55-captwc.142354_2.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(IngestedProduct::count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn ($message, $context) => $context['path'] === 'kwbc/kwbc_xous55-captwc.142354_2.txt');
});

test('a newly ingested VTEC product dispatches SyncActiveWwa instead of MatchWeatherAlertRules directly', function () {
    $syncSpy = Mockery::spy(SyncActiveWwa::class);
    app()->instance(SyncActiveWwa::class, $syncSpy);
    $matchSpy = Mockery::spy(MatchWeatherAlertRules::class);
    app()->instance(MatchWeatherAlertRules::class, $matchSpy);

    File::ensureDirectoryExists($this->productsRoot.'/kokx');
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\n";
    File::put($this->productsRoot.'/kokx/kokx_wfus51-torokx.061800_1.txt', $text);

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kokx/kokx_wfus51-torokx.061800_1.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    $syncSpy->shouldHaveReceived('__invoke')->once();
    $matchSpy->shouldNotHaveReceived('__invoke');
});
test('an NWWS product populates the header key and is skipped when EMWIN already ingested it', function () {
    $text = "WFUS53 KBOU 191622\nTORBOU\nBody";
    IngestedProduct::factory()->create(['file_path' => 'emwin/delivered/kbou/x.txt', 'source' => 'emwin', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::makeDirectory($this->productsRoot.'/kbou', recursive: true, force: true);
    File::put($this->productsRoot.'/kbou/kbou_wfus53-torbou.191622_1.txt', $text);

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kbou/kbou_wfus53-torbou.191622_1.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a first-seen NWWS product stores source nwws and its header key and records the nwws heartbeat', function () {
    File::makeDirectory($this->productsRoot.'/kbou', recursive: true, force: true);
    File::put($this->productsRoot.'/kbou/kbou_wfus53-torbou.191622_1.txt', "WFUS53 KBOU 191622\nTORBOU\nBody");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kbou/kbou_wfus53-torbou.191622_1.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    expect(IngestedProduct::sole()->source)->toBe('nwws')
        ->and(IngestedProduct::sole()->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and(PipelineHeartbeat::lastReceivedAt('nwws'))->not->toBeNull();
});

test('an ingested nwws product records a delivery for the nwws feed', function () {
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson(
        '/api/pipeline/nwws-products',
        ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'],
        ['Authorization' => 'Bearer test-webhook-token'],
    )->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('nwws')
        ->file_name->toBe('kkci_ftus21-tafkord.221432_12345.txt')
        ->outcome->toBe('ingested');
});

function enableNwwsHeartbeat(): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/nwws';
    $settings->save();
}

test('an nwws delivery queues a ping for the nwws feed', function () {
    Bus::fake();
    enableNwwsHeartbeat();
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 1);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'nwws');
});

test('an nwws delivery queues no ping when heartbeat is off or the path is rejected', function () {
    Bus::fake();
    File::put($this->productsRoot.'/kkci/kkci_ftus21-tafkord.221432_12345.txt', "FTUS21 KKCI 221432\nTAFORD\nsample");

    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/kkci_ftus21-tafkord.221432_12345.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNoContent();

    enableNwwsHeartbeat();
    $this->postJson('/api/pipeline/nwws-products', ['path' => 'kkci/missing.txt'], ['Authorization' => 'Bearer test-webhook-token'])->assertNotFound();

    // Not assertNothingDispatched(): the first request still legitimately
    // dispatches ProcessIngestedProduct (see the dedicated test above) --
    // this test is specifically about the heartbeat ping never firing.
    Bus::assertNotDispatched(SendHeartbeatPing::class);
});
