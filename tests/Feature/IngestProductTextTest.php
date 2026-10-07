<?php

use App\Actions\Pipeline\IngestProductText;
use App\Jobs\DeliverWeatherAlert;
use App\Models\AlertRule;
use App\Models\IngestedProduct;
use App\Models\NotificationChannel;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

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

test('the SPC fire weather outlooks, ingested under their real AWIPS ids, reach an SPC and WPC rule that watches them', function (string $code, string $example, string $awipsid, string $ttaaii) {
    // Only the delivery is faked: the observer's ProcessIngestedProduct job must really run (sync queue) to reach the matcher.
    Queue::fake([DeliverWeatherAlert::class]);
    $this->seed(ProductCatalogSeeder::class);
    $product = Product::where('code', $code)->sole();
    $rule = AlertRule::factory()->create(['category' => 'spc_wpc', 'matching_mode' => 'nationwide', 'location_id' => null]);
    $rule->products()->attach($product);
    $rule->notificationChannels()->attach(NotificationChannel::factory()->for($rule->user)->create());

    $ingested = app(IngestProductText::class)(file_get_contents(base_path("database/product_examples/{$example}.txt")), "test/{$awipsid}.txt", [
        'cccc' => 'kwns', 'ttaaii' => $ttaaii, 'awipsid' => $awipsid, 'wmo_ddhhmm' => '071627', 'nwws_id' => '1',
    ]);

    expect($ingested->product_id)->toBe($product->id);
    Queue::assertPushed(DeliverWeatherAlert::class, fn (DeliverWeatherAlert $job): bool => $job->ruleId === $rule->id && $job->ingestedProductId === $ingested->id);
})->with([
    'Day 1 outlook' => ['SPC.FIRE', 'FD1', 'fwddy1', 'fnus21'],
    'Day 2 outlook' => ['FD2', 'FD2', 'fwddy2', 'fnus22'],
    'Day 3-8 outlook' => ['F38', 'F38', 'fwdd38', 'fnus28'],
]);
