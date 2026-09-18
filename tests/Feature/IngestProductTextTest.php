<?php

use App\Actions\Pipeline\IngestProductText;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

test('ingests a text product and returns the created row', function () {
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    $text = "NYZ069-081200-\n/O.NEW.KOKX.TO.W.0042.260906T1800Z-260906T1900Z/\n";

    $result = app(IngestProductText::class)($text, 'test/path.txt', [
        'cccc' => 'kokx', 'ttaaii' => 'wfus51', 'awipsid' => 'torokx', 'wmo_ddhhmm' => '061800', 'nwws_id' => '1',
    ]);

    expect($result)->not->toBeNull()
        ->and($result->product_id)->toBe($tornadoWarning->id)
        ->and($result->ugc_codes)->toBe(['NYZ069'])
        ->and($result->file_path)->toBe('test/path.txt')
        ->and($result->text)->toBe($text)
        ->and($result->received_at)->not->toBeNull();
});

test('merges extra fields (e.g. is_test) into the created row', function () {
    $result = app(IngestProductText::class)('AREA FORECAST DISCUSSION', 'test/afd.txt', [
        'cccc' => 'okx', 'ttaaii' => 'fxus61', 'awipsid' => 'afdokx', 'wmo_ddhhmm' => '061800', 'nwws_id' => '1',
    ], extra: ['is_test' => true]);

    expect($result->is_test)->toBeTrue();
});

test('is idempotent for the same file_path, matching the real controller\'s existing behavior', function () {
    app(IngestProductText::class)('original text', 'test/path.txt', ['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null, 'nwws_id' => null]);
    app(IngestProductText::class)('updated text', 'test/path.txt', ['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null, 'nwws_id' => null]);

    expect(IngestedProduct::count())->toBe(1)
        ->and(IngestedProduct::sole()->text)->toBe('updated text');
});

test('runs the CAP extractor when $isCap is true, and returns null without creating a row on a malformed CAP payload', function () {
    Log::spy();

    $result = app(IngestProductText::class)("<?xml version=\"1.0\"?><alert><unclosed>\n", 'test/cap.txt', [
        'cccc' => 'kwbc', 'ttaaii' => 'xous55', 'awipsid' => 'captwc', 'wmo_ddhhmm' => '142354', 'nwws_id' => '1',
    ], isCap: true);

    expect($result)->toBeNull()
        ->and(IngestedProduct::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once();
});
