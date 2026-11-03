<?php

use App\Enums\ProductFamily;
use App\Models\Product;
use App\Support\SubjectPlaceholders;

const SUBJECT_ALL_PLACEHOLDERS = ['{product}', '{location}', '{category}', '{until}', '{states}', '{wfo}'];

test('a warning with VTEC and UGC offers every placeholder', function () {
    $product = Product::factory()->make(['family' => ProductFamily::Warning, 'has_vtec' => true, 'has_ugc' => true]);

    expect(SubjectPlaceholders::forProduct($product))->toBe(SUBJECT_ALL_PLACEHOLDERS);
});

test('a product without VTEC has no until placeholder', function () {
    $product = Product::factory()->make(['family' => ProductFamily::ForecastDiscussion, 'has_vtec' => false, 'has_ugc' => true]);

    expect(SubjectPlaceholders::forProduct($product))
        ->not->toContain('{until}')
        ->toContain('{states}', '{wfo}');
});

test('a product without UGC has no states placeholder', function () {
    $product = Product::factory()->make(['family' => ProductFamily::Warning, 'has_vtec' => true, 'has_ugc' => false]);

    expect(SubjectPlaceholders::forProduct($product))
        ->not->toContain('{states}')
        ->toContain('{until}');
});

test('an SPC and WPC product has no wfo placeholder', function () {
    $product = Product::factory()->make(['family' => ProductFamily::SpcWpc, 'has_vtec' => true, 'has_ugc' => true]);

    expect(SubjectPlaceholders::forProduct($product))
        ->not->toContain('{wfo}')
        ->toContain('{product}', '{location}', '{category}');
});

test('no product offers every placeholder', function () {
    expect(SubjectPlaceholders::forProduct(null))->toBe(SUBJECT_ALL_PLACEHOLDERS);
});

test('the helper text lists the placeholders separated by spaces', function () {
    $product = Product::factory()->make(['family' => ProductFamily::ForecastDiscussion, 'has_vtec' => false, 'has_ugc' => false]);

    expect(SubjectPlaceholders::helperText($product))->toBe('Placeholders: {product} {location} {category} {wfo}');
});

test('the real SPC and WPC discussions offer states but not wfo', function () {
    foreach (['SPC.MD', 'WPC.MPD'] as $code) {
        expect(SubjectPlaceholders::forProduct(Product::where('code', $code)->sole()))
            ->toBe(['{product}', '{location}', '{category}', '{states}']);
    }
});
