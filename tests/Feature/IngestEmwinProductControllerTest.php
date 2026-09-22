<?php

use App\Jobs\SendHeartbeatPing;
use App\Models\IngestedProduct;
use App\Models\PipelineDelivery;
use App\Settings\IntegrationSettings;
use App\Support\Pipeline\PipelineHeartbeat;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

function emwinApiWarning(string $extra = ''): string
{
    return "WFUS53 KBOU 191622{$extra}\nTORBOU\nCOZ039-191722-\n/O.NEW.KBOU.TO.W.0009.260919T1622Z-260919T1722Z/\n\nBULLETIN\nTornado Warning";
}

function emwinApiPost(object $t, string $path, mixed $feed = null)
{
    $body = ['path' => $path] + ($feed === null ? [] : ['feed' => $feed]);

    return $t->postJson('/api/pipeline/emwin-products', $body, ['Authorization' => 'Bearer test-webhook-token']);
}

beforeEach(function () {
    Config::set('services.pipeline.webhook_token', 'test-webhook-token');
    $this->emwinRoot = sys_get_temp_dir().'/emwin-products-test-'.uniqid();
    File::makeDirectory($this->emwinRoot.'/delivered/kbou', recursive: true);
    Config::set('services.pipeline.emwin_products_path', $this->emwinRoot);
});

afterEach(fn () => File::deleteDirectory($this->emwinRoot));

test('it requires the pipeline bearer token', function () {
    $this->postJson('/api/pipeline/emwin-products', ['path' => 'x'])->assertUnauthorized();
    $this->postJson('/api/pipeline/emwin-products', ['path' => 'x'], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
});

test('it ingests a product with identity from the WMO header, source emwin and a prefixed file path', function () {
    File::put($this->emwinRoot.'/delivered/kbou/TORBOU_1.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/TORBOU_1.txt')->assertNoContent();

    $product = IngestedProduct::sole();
    expect($product->source)->toBe('emwin')
        ->and($product->file_path)->toBe('emwin/delivered/kbou/TORBOU_1.txt')
        ->and($product->cccc)->toBe('kbou')->and($product->ttaaii)->toBe('wfus53')
        ->and($product->awipsid)->toBe('torbou')->and($product->wmo_ddhhmm)->toBe('191622')
        ->and($product->wmo_header_key)->toBe('wfus53 kbou 191622 torbou')
        ->and($product->vtec_event_number)->toBe('0009');
});

test('a product with no parseable header is still ingested, without a key', function () {
    File::put($this->emwinRoot.'/delivered/kbou/odd.txt', 'no header here');

    emwinApiPost($this, 'delivered/kbou/odd.txt')->assertNoContent();

    expect(IngestedProduct::sole()->wmo_header_key)->toBeNull();
});

test('paths that escape the root or do not exist return 404', function () {
    File::put(dirname($this->emwinRoot).'/outside-'.basename($this->emwinRoot).'.txt', 'x');

    emwinApiPost($this, 'missing.txt')->assertNotFound();
    emwinApiPost($this, '../outside-'.basename($this->emwinRoot).'.txt')->assertNotFound();
    expect(IngestedProduct::count())->toBe(0);

    File::delete(dirname($this->emwinRoot).'/outside-'.basename($this->emwinRoot).'.txt');
});

test('a product NWWS already ingested is not ingested again from EMWIN', function () {
    IngestedProduct::factory()->create(['file_path' => 'ktwc/nwws.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou', 'source' => 'nwws']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a differing amendment indicator or time is not a duplicate', function () {
    IngestedProduct::factory()->create(['file_path' => 'a/b.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/cor.txt', emwinApiWarning(' CCA'));

    emwinApiPost($this, 'delivered/kbou/cor.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(2);
});

test('every accepted post records the emwin heartbeat, including deduplicated ones', function () {
    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->toBeNull();
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt')->assertNoContent();

    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->not->toBeNull();
});

test('it returns 503 when another feed holds the lock for the same header key', function () {
    Config::set('services.pipeline.wmo_lock_wait_seconds', 1);
    File::put($this->emwinRoot.'/delivered/kbou/locked.txt', emwinApiWarning());
    $lock = Cache::lock('wmo-header:wfus53 kbou 191622 torbou', 10);
    expect($lock->get())->toBeTrue();

    emwinApiPost($this, 'delivered/kbou/locked.txt')->assertStatus(503);

    expect(IngestedProduct::count())->toBe(0);
    $lock->release();
});

test('a post with no feed records the byteblaster heartbeat only', function () {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->not->toBeNull()
        ->and(PipelineHeartbeat::lastReceivedAt('emwin_http'))->toBeNull();
});

test('feed byteblaster behaves like the default', function () {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt', 'byteblaster')->assertNoContent();

    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->not->toBeNull()
        ->and(PipelineHeartbeat::lastReceivedAt('emwin_http'))->toBeNull();
});

test('feed http records the http heartbeat only and still ingests with source emwin', function () {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt', 'http')->assertNoContent();

    expect(PipelineHeartbeat::lastReceivedAt('emwin_http'))->not->toBeNull()
        ->and(PipelineHeartbeat::lastReceivedAt('emwin'))->toBeNull()
        ->and(IngestedProduct::sole()->source)->toBe('emwin');
});

test('an invalid feed is rejected with 422 and records no heartbeat', function (mixed $feed) {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt', $feed)->assertUnprocessable();

    expect(PipelineHeartbeat::lastReceivedAt('emwin'))->toBeNull()
        ->and(PipelineHeartbeat::lastReceivedAt('emwin_http'))->toBeNull()
        ->and(IngestedProduct::count())->toBe(0);
})->with(['unknown value' => 'ftp', 'array' => [['http']], 'integer' => 5]);

test('the same bulletin arriving on both feeds is ingested once', function () {
    File::makeDirectory($this->emwinRoot.'/http', recursive: true);
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());
    File::put($this->emwinRoot.'/http/b.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt', 'byteblaster')->assertNoContent();
    emwinApiPost($this, 'http/b.txt', 'http')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('an ingested byteblaster post records an ingested delivery for the emwin feed', function () {
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('emwin')
        ->wmo_header_key->toBe('wfus53 kbou 191622 torbou')
        ->file_name->toBe('a.txt')
        ->outcome->toBe('ingested');
});

test('an http post records the emwin_http feed and a deduplicated one records a duplicate', function () {
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt', 'http')->assertNoContent();

    expect(PipelineDelivery::sole())
        ->feed->toBe('emwin_http')
        ->outcome->toBe('duplicate');
});

test('a failure to record a delivery never fails the ingest request', function () {
    PipelineDelivery::creating(fn () => throw new RuntimeException('boom'));
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});

test('a rejected path records no delivery', function () {
    emwinApiPost($this, 'missing.txt')->assertNotFound();

    expect(PipelineDelivery::count())->toBe(0);
});

function enableHeartbeatUrls(): void
{
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_enabled = true;
    $settings->heartbeat_nwws_url = 'https://hc.example.test/ping/nwws';
    $settings->heartbeat_emwin_url = 'https://hc.example.test/ping/emwin';
    $settings->heartbeat_emwin_http_url = 'https://hc.example.test/ping/http';
    $settings->save();
}

test('a byteblaster delivery queues a ping for the emwin feed only', function () {
    Bus::fake();
    enableHeartbeatUrls();
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    Bus::assertDispatchedTimes(SendHeartbeatPing::class, 1);
    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin');
});

test('an http delivery queues a ping for the emwin_http feed, even when it is a duplicate', function () {
    Bus::fake();
    enableHeartbeatUrls();
    IngestedProduct::factory()->create(['file_path' => 'x/y.txt', 'wmo_header_key' => 'wfus53 kbou 191622 torbou']);
    File::put($this->emwinRoot.'/delivered/kbou/dup.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/dup.txt', 'http')->assertNoContent();

    Bus::assertDispatched(SendHeartbeatPing::class, fn (SendHeartbeatPing $job) => $job->feed === 'emwin_http');
    expect(IngestedProduct::count())->toBe(1);
});

test('no ping is queued when heartbeat is off, the feed url is blank, or the path is rejected', function () {
    Bus::fake();
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();
    Bus::assertNothingDispatched();

    enableHeartbeatUrls();
    $settings = app(IntegrationSettings::class);
    $settings->heartbeat_emwin_url = null;
    $settings->save();
    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();
    Bus::assertNothingDispatched();

    $settings->heartbeat_emwin_url = 'https://hc.example.test/ping/emwin';
    $settings->save();
    emwinApiPost($this, 'missing.txt')->assertNotFound();
    Bus::assertNothingDispatched();
});

test('a queueing failure never fails the ingest request', function () {
    enableHeartbeatUrls();
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue down'));
    $this->instance(Dispatcher::class, $dispatcher);
    File::put($this->emwinRoot.'/delivered/kbou/a.txt', emwinApiWarning());

    emwinApiPost($this, 'delivered/kbou/a.txt')->assertNoContent();

    expect(IngestedProduct::count())->toBe(1);
});
