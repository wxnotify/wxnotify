<?php

use App\Models\Product;

test('the backfill sets has_vtec/has_ugc correctly for the real seeded catalog, including the SVS/SPS split', function () {
    expect(Product::where('code', 'TO.W')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'TO.W')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'SVS')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'SPS')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'SPS')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'AFD')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'AFD')->sole()->has_ugc)->toBeFalse()
        ->and(Product::where('code', 'SPC.MD')->sole()->has_vtec)->toBeFalse();
});

test('the SPC and WPC discussions are marked as carrying UGC codes, as their bulletins do', function () {
    expect(Product::where('code', 'SPC.MD')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'WPC.MPD')->sole()->has_ugc)->toBeTrue()
        ->and(Product::where('code', 'SPC.OUTLOOK')->sole()->has_ugc)->toBeFalse();
});

test('damage_tag_options casts to an array and is fillable', function () {
    $product = Product::factory()->create(['damage_tag_options' => ['TAG A', 'TAG B']]);

    expect($product->fresh()->damage_tag_options)->toBe(['TAG A', 'TAG B']);
});

test('has_vtec and has_ugc cast to boolean and are fillable', function () {
    $product = Product::factory()->create(['has_vtec' => true, 'has_ugc' => true]);

    expect($product->fresh()->has_vtec)->toBeTrue()
        ->and($product->fresh()->has_ugc)->toBeTrue();
});
