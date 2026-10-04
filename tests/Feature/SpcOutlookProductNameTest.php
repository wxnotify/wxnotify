<?php

use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Facades\DB;

function renameDay1Migration(): object
{
    $path = collect(glob(database_path('migrations/*_rename_spc_outlook_product_to_day_1.php')))->sole();

    return require $path;
}

test('the Day 1 convective outlook is named like the other days', function () {
    (new ProductCatalogSeeder)->run();

    expect(DB::table('products')->where('code', 'SPC.OUTLOOK')->value('name'))->toBe('SPC Day 1 Convective Outlook')
        ->and(DB::table('products')->whereIn('code', ['DY2', 'DY3', 'D48'])->orderBy('code')->pluck('name')->all())
        ->toBe(['SPC Day 4-8 Convective Outlook', 'SPC Day 2 Convective Outlook', 'SPC Day 3 Convective Outlook']);
});

test('the rename migration leaves a name staff edited alone, and reverses cleanly', function () {
    DB::table('products')->where('code', 'SPC.OUTLOOK')->update(['name' => 'Our convective outlook']);
    renameDay1Migration()->up();

    expect(DB::table('products')->where('code', 'SPC.OUTLOOK')->value('name'))->toBe('Our convective outlook');

    DB::table('products')->where('code', 'SPC.OUTLOOK')->update(['name' => 'SPC Convective Outlook']);
    renameDay1Migration()->up();
    expect(DB::table('products')->where('code', 'SPC.OUTLOOK')->value('name'))->toBe('SPC Day 1 Convective Outlook');

    renameDay1Migration()->down();
    expect(DB::table('products')->where('code', 'SPC.OUTLOOK')->value('name'))->toBe('SPC Convective Outlook');
});
