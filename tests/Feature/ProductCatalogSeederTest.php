<?php

use App\Enums\ProductCategory;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;

test('merges the legacy catalog into the existing products without duplicating matched rows', function () {
    $before = Product::count();

    $this->seed(ProductCatalogSeeder::class);

    expect(Product::count())->toBe(152);

    // Matched product: code/name/family/status are untouched, new columns are filled in.
    $tornadoWarning = Product::where('code', 'TO.W')->sole();
    expect($tornadoWarning->name)->toBe('Tornado Warning')
        ->and($tornadoWarning->family)->toBe(ProductFamily::Warning)
        ->and($tornadoWarning->category)->toBe(ProductCategory::TornadoAndSevereThunderstorm)
        ->and($tornadoWarning->wea_warning)->toBeTrue()
        ->and($tornadoWarning->description)->not->toBeEmpty()
        ->and($tornadoWarning->example)->not->toBeEmpty();

    // New product: inserted using the legacy abbr as its code.
    $civilDanger = Product::where('code', 'CDW')->sole();
    expect($civilDanger->name)->toBe('Civil Danger Warning')
        ->and($civilDanger->family)->toBe(ProductFamily::Administrative)
        ->and($civilDanger->category)->toBe(ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages)
        ->and($civilDanger->status)->toBe(ProductStatus::Active);

    // Orphan product with no legacy counterpart is removed.
    expect(Product::where('code', 'SS.W')->exists())->toBeFalse();

    expect(Product::count())->not->toBe($before);
});

test('is idempotent — running it twice does not duplicate or lose rows', function () {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(ProductCatalogSeeder::class);

    expect(Product::count())->toBe(152)
        ->and(Product::where('code', 'TO.W')->count())->toBe(1)
        ->and(Product::where('code', 'CDW')->count())->toBe(1);
});

test('a manual edit to a matched product survives a re-run except for the merged columns', function () {
    $this->seed(ProductCatalogSeeder::class);

    Product::where('code', 'TO.W')->update(['name' => 'Staff-Renamed Warning']);

    $this->seed(ProductCatalogSeeder::class);

    expect(Product::where('code', 'TO.W')->sole()->name)->toBe('Staff-Renamed Warning');
});
