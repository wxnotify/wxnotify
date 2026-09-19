<?php

use Database\Seeders\ChannelTypeSeeder;
use Illuminate\Support\Facades\DB;

test('the seeder restores edited shipped channel types and leaves custom ones to the caller', function () {
    DB::table('channel_types')->where('slug', 'email')->update(['enabled' => false, 'min_tier' => 'pro', 'sort_order' => 99]);
    DB::table('channel_types')->where('slug', 'slack')->delete();

    (new ChannelTypeSeeder)->run();

    expect(DB::table('channel_types')->where('slug', 'email')->first())
        ->enabled->toBeTrue()->min_tier->toBe('free')->sort_order->toBe(1)
        ->and(DB::table('channel_types')->where('slug', 'slack')->exists())->toBeTrue()
        ->and(DB::table('channel_types')->count())->toBe(count(ChannelTypeSeeder::SHIPPED));
});

test('the migration and the seeder agree on the shipped set', function () {
    expect(DB::table('channel_types')->orderBy('sort_order')->pluck('slug')->all())
        ->toBe(array_column(ChannelTypeSeeder::SHIPPED, 'slug'));
});
