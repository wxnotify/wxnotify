<?php

use App\Actions\Products\GetProductDetails;
use App\Models\IngestedProduct;

function detailsProduct(array $attributes = []): IngestedProduct
{
    return IngestedProduct::factory()->create([
        'cccc' => 'kewx',
        'ttaaii' => 'fous54',
        'awipsid' => 'pfwewx',
        'wmo_ddhhmm' => '200625',
        'received_at' => '2026-09-20 06:25:53',
        'source' => 'nwws',
        ...$attributes,
    ])->fresh();
}

test('a full NWWS product with VTEC yields every line in order', function () {
    $product = detailsProduct([
        'vtec_action' => 'NEW', 'vtec_office' => 'KEWX', 'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W', 'vtec_event_number' => '0042', 'vtec_year' => 2026,
        'vtec_begin' => '2026-09-20 06:25:00', 'vtec_end' => '2026-09-20 07:00:00',
    ]);

    expect(app(GetProductDetails::class)($product))->toBe([
        'Office' => 'KEWX',
        'Issued' => '2026-09-20 06:25 UTC',
        'WMO header' => 'FOUS54 KEWX 200625',
        'AWIPS ID' => 'PFWEWX',
        'Source' => 'NWWS',
        'VTEC' => 'NEW.KEWX.TO.W.0042 (2026)',
        'Valid from' => '2026-09-20 06:25 UTC',
        'Valid until' => '2026-09-20 07:00 UTC',
    ]);
});

test('a product with no office, WMO header or AWIPS ID leaves those labels out', function () {
    $product = detailsProduct(['cccc' => null, 'ttaaii' => null, 'awipsid' => null, 'wmo_ddhhmm' => null]);

    expect(app(GetProductDetails::class)($product))->toBe([
        'Issued' => '2026-09-20 06:25 UTC',
        'Source' => 'NWWS',
    ]);
});

test('the EMWIN source reads EMWIN', function () {
    expect(app(GetProductDetails::class)(detailsProduct(['source' => 'emwin']))['Source'])->toBe('EMWIN');
});
