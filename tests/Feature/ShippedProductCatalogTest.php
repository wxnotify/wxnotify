<?php

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

test('the committed shipped catalog snapshot matches a freshly seeded catalog', function () {
    (new ProductCatalogSeeder)->run();

    $live = DB::table('products')->orderBy('code')->get()
        ->map(fn ($row) => collect((array) $row)->except(['id', 'description', 'example', 'created_at', 'updated_at'])->all())
        ->all();

    expect($live)->toBe(require database_path('data/shipped_product_catalog.php'));
})->group('catalog-snapshot');
