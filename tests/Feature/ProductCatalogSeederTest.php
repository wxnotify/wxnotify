<?php

use App\Enums\ProductCategory;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use App\Models\Product;
use Database\Seeders\ProductCatalogSeeder;

test('merges the legacy catalog into the existing products without duplicating matched rows', function () {
    $before = Product::count();

    $this->seed(ProductCatalogSeeder::class);

    expect(Product::count())->toBe(155);

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

    // Orphan product with no legacy counterpart is removed; storm surge and the excessive rainfall outlook are kept.
    expect(Product::where('code', 'LE.W')->exists())->toBeFalse()
        ->and(Product::whereIn('code', ['SS.A', 'SS.W', 'WPC.ERO'])->count())->toBe(3);

    expect(Product::count())->not->toBe($before);
});

test('is idempotent — running it twice does not duplicate or lose rows', function () {
    $this->seed(ProductCatalogSeeder::class);
    $this->seed(ProductCatalogSeeder::class);

    expect(Product::count())->toBe(155)
        ->and(Product::where('code', 'TO.W')->count())->toBe(1)
        ->and(Product::where('code', 'CDW')->count())->toBe(1);
});

test('a manual edit to a matched product survives a re-run except for the merged columns', function () {
    $this->seed(ProductCatalogSeeder::class);

    Product::where('code', 'TO.W')->update(['name' => 'Staff-Renamed Warning']);

    $this->seed(ProductCatalogSeeder::class);

    expect(Product::where('code', 'TO.W')->sole()->name)->toBe('Staff-Renamed Warning');
});

test('sets has_vtec/has_ugc for every new Warning/Watch/Advisory product, with FFS correctly flagged as a VTEC continuation', function () {
    $this->seed(ProductCatalogSeeder::class);

    // FAW (Areal Flood Warning) is a plain Warning-family new product.
    expect(Product::where('code', 'FAW')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'FAW')->sole()->has_ugc)->toBeTrue()
        // FFS (Flash Flood Statement) is Statement-family but is the well-established
        // VTEC-continuation statement for FF.W, so it's explicitly overridden to true.
        ->and(Product::where('code', 'FFS')->sole()->has_vtec)->toBeTrue()
        ->and(Product::where('code', 'FFS')->sole()->has_ugc)->toBeTrue()
        // HLS and MAS are also Statement-family, but their VTEC-continuation status
        // isn't confidently established, so they stay at the safe false/false default.
        ->and(Product::where('code', 'HLS')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'HLS')->sole()->has_ugc)->toBeFalse()
        ->and(Product::where('code', 'MAS')->sole()->has_vtec)->toBeFalse()
        ->and(Product::where('code', 'MAS')->sole()->has_ugc)->toBeFalse();
});

test('the storm surge products and the excessive rainfall outlook are kept, filled in like matched products, and survive a re-run', function () {
    $this->seed(ProductCatalogSeeder::class);

    $warning = Product::where('code', 'SS.W')->sole();
    $watch = Product::where('code', 'SS.A')->sole();
    $outlook = Product::where('code', 'WPC.ERO')->sole();

    expect($warning->category)->toBe(ProductCategory::HurricaneAndTropicalStorm)
        ->and($watch->category)->toBe(ProductCategory::HurricaneAndTropicalStorm)
        ->and($warning->wea_warning)->toBeTrue()
        ->and($watch->wea_warning)->toBeFalse()
        ->and($warning->description)->not->toBeEmpty()
        ->and($watch->description)->not->toBeEmpty()
        ->and($warning->example)->not->toBeEmpty()
        ->and($watch->example)->not->toBeEmpty()
        ->and($outlook->category)->toBe(ProductCategory::FloodingAndHydrological)
        ->and($outlook->wea_warning)->toBeFalse()
        ->and($outlook->description)->not->toBeEmpty()
        ->and($outlook->example)->not->toBeEmpty()
        ->and($outlook->risk_level_options)->toBe(['Marginal', 'Slight', 'Moderate', 'High']);

    Product::where('code', 'SS.W')->update(['name' => 'Staff-Renamed Surge Warning']);
    Product::where('code', 'WPC.ERO')->update(['name' => 'Staff-Renamed Rainfall Outlook']);

    $this->seed(ProductCatalogSeeder::class);

    expect(Product::where('code', 'SS.W')->sole()->name)->toBe('Staff-Renamed Surge Warning')
        ->and(Product::where('code', 'SS.W')->sole()->category)->toBe(ProductCategory::HurricaneAndTropicalStorm)
        ->and(Product::where('code', 'WPC.ERO')->sole()->name)->toBe('Staff-Renamed Rainfall Outlook')
        ->and(Product::where('code', 'WPC.ERO')->sole()->category)->toBe(ProductCategory::FloodingAndHydrological);
});
