<?php

use App\Models\IngestedProduct;
use App\Models\Product;
use App\Models\TestProductScenario;

test('a scenario belongs to a product, can continue another scenario, and casts input to an array', function () {
    $product = Product::where('code', 'TO.W')->sole();
    $original = TestProductScenario::factory()->for($product)->create(['input' => ['wfo' => 'TWC']]);
    $followUp = TestProductScenario::factory()->for($product)->create(['continues_scenario_id' => $original->id]);

    expect($followUp->fresh()->continuesScenario->is($original))->toBeTrue()
        ->and($original->fresh()->product->is($product))->toBeTrue()
        ->and($original->fresh()->input)->toBe(['wfo' => 'TWC']);
});

test('a scenario tracks every ingested product it has spawned', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['test_product_scenario_id' => $scenario->id, 'is_test' => true]);

    expect($scenario->fresh()->ingestedProducts->pluck('id')->all())->toBe([$product->id]);
});

test('deleting a scenario nulls out is_test rows that reference it, rather than deleting them', function () {
    $scenario = TestProductScenario::factory()->create();
    $product = IngestedProduct::factory()->create(['test_product_scenario_id' => $scenario->id, 'is_test' => true]);

    $scenario->delete();

    expect($product->fresh()->test_product_scenario_id)->toBeNull()
        ->and(IngestedProduct::whereKey($product->id)->exists())->toBeTrue();
});
