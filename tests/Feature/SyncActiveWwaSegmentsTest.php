<?php

use App\Actions\Pipeline\IngestProductText;
use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\ActiveWwa;
use App\Models\IngestedProduct;
use App\Models\Product;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

/** A CAP message for one zone of the Mobile Hurricane Watch, through the observer (so segments are built and the event synced). */
function syncSegmentsCapMessage(string $zone, string $action = 'NEW', ?Carbon $receivedAt = null): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'format' => 'cap', 'text' => '<?xml version="1.0"?><alert/>', 'cccc' => 'kwbc',
        'product_id' => Product::where('code', 'HU.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'HU', 'vtec_significance' => 'A', 'vtec_event_number' => '1009',
        'vtec_year' => now()->year, 'vtec_action' => $action, 'ugc_codes' => [$zone], 'received_at' => $receivedAt ?? now(),
    ]);
}

test('a Hurricane Local Statement gives each of its events a row with every zone of its sections', function () {
    app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/TCV_MOB.txt')),
        'test/tcvmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'wtus84', 'awipsid' => 'tcvmob', 'wmo_ddhhmm' => '071609', 'nwws_id' => '1'],
    );

    $watch = ActiveWwa::where('vtec_phenomenon', 'HU')->sole();
    $surge = ActiveWwa::where('vtec_phenomenon', 'SS')->sole();
    expect(ActiveWwa::count())->toBe(2)
        ->and($watch->areas()->count())->toBe(12)
        ->and($watch->areas()->where('ugc_code', 'FLZ206')->exists())->toBeTrue()
        ->and($surge->areas()->count())->toBe(7)
        ->and($surge->areas()->where('ugc_code', 'FLZ206')->exists())->toBeTrue()
        ->and($watch->status)->toBe('Active');
});

test('a section\'s second VTEC line gets its own event row', function () {
    app(IngestProductText::class)(
        file_get_contents(base_path('database/product_examples/bulletins/MWW_MOB.txt')),
        'test/mwwmob.txt',
        ['cccc' => 'kmob', 'ttaaii' => 'whus74', 'awipsid' => 'mwwmob', 'wmo_ddhhmm' => '071538', 'nwws_id' => '1'],
    );

    $advisory = ActiveWwa::where('vtec_phenomenon', 'SC')->sole();
    expect($advisory->vtec_event_number)->toBe('0031')
        ->and($advisory->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['GMZ650', 'GMZ655', 'GMZ670', 'GMZ675'])
        ->and(ActiveWwa::where('vtec_phenomenon', 'HU')->sole()->areas()->count())->toBe(11);
});

test('CAP messages for one zone each add up on one event instead of overwriting each other', function () {
    syncSegmentsCapMessage('FLZ206');
    syncSegmentsCapMessage('FLZ203');
    syncSegmentsCapMessage('ALZ266');

    $wwa = ActiveWwa::sole();
    expect($wwa->areas()->pluck('ugc_code')->sort()->values()->all())->toBe(['ALZ266', 'FLZ203', 'FLZ206'])
        ->and($wwa->source)->toBe('cap')
        ->and($wwa->status)->toBe('Active');
});

test('a CAP cancel for one zone ends that zone and not the event', function () {
    syncSegmentsCapMessage('FLZ206');
    syncSegmentsCapMessage('FLZ203');

    syncSegmentsCapMessage('FLZ206', 'CAN', now()->addMinute());

    $wwa = ActiveWwa::sole();
    expect($wwa->status)->toBe('Active')
        ->and($wwa->areas()->where('ugc_code', 'FLZ206')->sole()->status)->toBe('Cancelled')
        ->and($wwa->areas()->where('ugc_code', 'FLZ203')->sole()->status)->toBe('Active');
});

test('an upgrade bulletin ends its zones', function () {
    $watch = IngestedProduct::factory()->create([
        'format' => 'text', 'text' => 'Tropical Storm Watch', 'product_id' => Product::where('code', 'TR.A')->sole()->id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'TR', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => now()->year,
        'vtec_action' => 'NEW', 'ugc_codes' => ['FLZ206'], 'received_at' => now(),
    ]);

    IngestedProduct::factory()->create([
        'format' => 'text', 'text' => 'Upgraded to a Tropical Storm Warning', 'product_id' => $watch->product_id,
        'vtec_office' => 'KMOB', 'vtec_phenomenon' => 'TR', 'vtec_significance' => 'A', 'vtec_event_number' => '1009', 'vtec_year' => now()->year,
        'vtec_action' => 'UPG', 'ugc_codes' => ['FLZ206'], 'received_at' => now()->addMinute(),
    ]);

    expect(ActiveWwa::sole()->status)->toBe('Cancelled');
});

test('a text bulletin with no stored segments is applied from its own columns, as before', function () {
    $bulletin = IngestedProduct::withoutEvents(fn () => IngestedProduct::factory()->create([
        'format' => 'text', 'product_id' => Product::where('code', 'TO.W')->sole()->id,
        'vtec_office' => 'KOKX', 'vtec_phenomenon' => 'TO', 'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
        'vtec_action' => 'NEW', 'ugc_codes' => ['NYZ069'], 'received_at' => now(),
    ]));
    app()->instance(MatchWeatherAlertRules::class, Mockery::spy(MatchWeatherAlertRules::class));

    (new SyncActiveWwa)($bulletin);

    $wwa = ActiveWwa::sole();
    expect($wwa->areas()->pluck('ugc_code')->all())->toBe(['NYZ069'])
        ->and($wwa->latest_ingested_product_id)->toBe($bulletin->id);
});
