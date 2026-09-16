<?php

use App\Models\ActiveWwa;
use App\Models\IngestedProduct;

test('forProduct scope matches only the row sharing the full 5-column VTEC key', function () {
    $matching = ActiveWwa::factory()->create([
        'vtec_office' => 'KOKX',
        'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0042',
        'vtec_year' => 2026,
    ]);

    // Differs only by vtec_event_number.
    ActiveWwa::factory()->create([
        'vtec_office' => 'KOKX',
        'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0099',
        'vtec_year' => 2026,
    ]);

    // Differs only by vtec_year.
    ActiveWwa::factory()->create([
        'vtec_office' => 'KOKX',
        'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0042',
        'vtec_year' => 2025,
    ]);

    $product = IngestedProduct::factory()->create([
        'vtec_office' => 'KOKX',
        'vtec_phenomenon' => 'TO',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0042',
        'vtec_year' => 2026,
    ]);

    $result = ActiveWwa::query()->forProduct($product)->get();

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe($matching->id);
});

test('vtecKeyFor returns the 5 VTEC columns keyed for use as updateOrCreate attributes', function () {
    $product = IngestedProduct::factory()->create([
        'vtec_office' => 'KTWC',
        'vtec_phenomenon' => 'FF',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0209',
        'vtec_year' => 2026,
    ]);

    expect(ActiveWwa::vtecKeyFor($product))->toBe([
        'vtec_office' => 'KTWC',
        'vtec_phenomenon' => 'FF',
        'vtec_significance' => 'W',
        'vtec_event_number' => '0209',
        'vtec_year' => 2026,
    ]);
});
