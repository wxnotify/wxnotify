<?php

use App\Models\IngestedProduct;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

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
