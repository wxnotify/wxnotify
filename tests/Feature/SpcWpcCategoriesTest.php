<?php

use App\Enums\ProductCategory;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

const SPC_WPC_MOVES = [
    'convective_outlooks' => ['SPC.OUTLOOK', 'DY2', 'DY3', 'D48'],
    'other_discussions_reports_and_outlooks' => ['SPC.MD', 'STA', 'PWO'],
];

function splitCategoriesMigration(): object
{
    return require collect(glob(database_path('migrations/*_split_spc_wpc_product_categories.php')))->sole();
}

test('the new categories have a unique value, a label and an icon', function () {
    expect(ProductCategory::ConvectiveOutlooks->value)->toBe('convective_outlooks')
        ->and(ProductCategory::ConvectiveOutlooks->label())->toBe('Convective Outlooks')
        ->and(ProductCategory::OtherDiscussionsReportsAndOutlooks->value)->toBe('other_discussions_reports_and_outlooks')
        ->and(ProductCategory::OtherDiscussionsReportsAndOutlooks->label())->toBe('Other Discussions, Reports, and Outlooks');

    $values = array_map(fn (ProductCategory $c): string => $c->value, ProductCategory::cases());

    expect($values)->toHaveCount(count(array_unique($values)));

    foreach (ProductCategory::cases() as $category) {
        expect($category->label())->not->toBe('')->and($category->icon())->toStartWith('fas-');
    }
});

test('the new categories sit after the old catch-all so the SPC & WPC form lists them in order', function () {
    $order = array_map(fn (ProductCategory $c): string => $c->value, ProductCategory::cases());

    expect(array_search('flooding_and_hydrological', $order, true))->toBeLessThan(array_search('fire_weather', $order, true))
        ->and(array_search('fire_weather', $order, true))->toBeLessThan(array_search('convective_outlooks', $order, true))
        ->and(array_search('convective_outlooks', $order, true))->toBeLessThan(array_search('other_discussions_reports_and_outlooks', $order, true));
});

test('the seeded catalog puts every SPC & WPC product in its category', function () {
    $this->seed(ProductCatalogSeeder::class);

    foreach (SPC_WPC_MOVES as $category => $codes) {
        expect(DB::table('products')->whereIn('code', $codes)->pluck('category', 'code')->all())
            ->toEqual(array_fill_keys($codes, $category));
    }

    expect(DB::table('products')->where('family', 'spc_wpc')->orderBy('code')->pluck('category', 'code')->all())
        ->toMatchArray([
            'SPC.FIRE' => 'fire_weather', 'FD2' => 'fire_weather', 'F38' => 'fire_weather',
            'WPC.MPD' => 'flooding_and_hydrological',
        ]);
});

test('the Weather Alerts products keep the old catch-all category', function () {
    $this->seed(ProductCatalogSeeder::class);

    expect(DB::table('products')->where('code', 'AFD')->value('category'))->toBe('other_forecasts_outlooks_discussions_and_reports');
});

test('the migration moves the seven products out of the old category, and only from it', function () {
    $this->seed(ProductCatalogSeeder::class);
    $old = 'other_forecasts_outlooks_discussions_and_reports';
    DB::table('products')->whereIn('code', [...SPC_WPC_MOVES['convective_outlooks'], ...SPC_WPC_MOVES['other_discussions_reports_and_outlooks']])->update(['category' => $old]);
    DB::table('products')->where('code', 'SPC.MD')->update(['category' => 'fire_weather']); // staff chose another category

    splitCategoriesMigration()->up();

    expect(DB::table('products')->whereIn('code', SPC_WPC_MOVES['convective_outlooks'])->pluck('category')->unique()->all())->toBe(['convective_outlooks'])
        ->and(DB::table('products')->whereIn('code', ['STA', 'PWO'])->pluck('category')->unique()->all())->toBe(['other_discussions_reports_and_outlooks'])
        ->and(DB::table('products')->where('code', 'SPC.MD')->value('category'))->toBe('fire_weather');
});

test('the migration reverses cleanly', function () {
    $this->seed(ProductCatalogSeeder::class);

    splitCategoriesMigration()->down();

    expect(DB::table('products')->whereIn('code', [...SPC_WPC_MOVES['convective_outlooks'], ...SPC_WPC_MOVES['other_discussions_reports_and_outlooks']])->pluck('category')->unique()->all())
        ->toBe(['other_forecasts_outlooks_discussions_and_reports']);
});
