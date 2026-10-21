<?php

use Illuminate\Support\Facades\DB;

function renameFireDay1Migration(): object
{
    $path = collect(glob(database_path('migrations/*_rename_spc_fire_product_to_day_1.php')))->sole();

    return require $path;
}

test('the Day 1 fire weather outlook is named like the other days', function () {
    expect(DB::table('products')->where('code', 'SPC.FIRE')->value('name'))->toBe('SPC Day 1 Fire Weather Outlook');
});

test('the fire rename migration leaves a name staff edited alone, and reverses cleanly', function () {
    DB::table('products')->where('code', 'SPC.FIRE')->update(['name' => 'Our fire outlook']);
    renameFireDay1Migration()->up();

    expect(DB::table('products')->where('code', 'SPC.FIRE')->value('name'))->toBe('Our fire outlook');

    DB::table('products')->where('code', 'SPC.FIRE')->update(['name' => 'SPC Fire Weather Outlook']);
    renameFireDay1Migration()->up();
    expect(DB::table('products')->where('code', 'SPC.FIRE')->value('name'))->toBe('SPC Day 1 Fire Weather Outlook');

    renameFireDay1Migration()->down();
    expect(DB::table('products')->where('code', 'SPC.FIRE')->value('name'))->toBe('SPC Fire Weather Outlook');
});
