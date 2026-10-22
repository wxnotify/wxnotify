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

test('the migrations and the seeder agree on every shipped row', function () {
    $live = DB::table('channel_types')->orderBy('sort_order')->get(['slug', 'min_tier', 'sort_order', 'enabled'])
        ->map(fn (object $row): array => [
            'slug' => $row->slug, 'min_tier' => $row->min_tier, 'sort_order' => (int) $row->sort_order, 'enabled' => (bool) $row->enabled,
        ])->all();

    expect($live)->toBe(ChannelTypeSeeder::SHIPPED);
});

test('the phone types ship disabled and plus-only', function () {
    $phoneSlugs = ['twilio_sms', 'bulkvs_sms', 'httpsms'];

    $fromSeeder = collect(ChannelTypeSeeder::SHIPPED)->whereIn('slug', $phoneSlugs);
    expect($fromSeeder)->toHaveCount(3)
        ->and($fromSeeder->every(fn (array $row): bool => $row['enabled'] === false && $row['min_tier'] === 'plus'))->toBeTrue();

    DB::table('channel_types')->whereIn('slug', $phoneSlugs)->delete();
    (require database_path('migrations/2026_10_22_100001_add_sms_channel_types.php'))->up();

    $fromMigration = DB::table('channel_types')->whereIn('slug', $phoneSlugs)->get();
    expect($fromMigration)->toHaveCount(3)
        ->and($fromMigration->every(fn (object $row): bool => ! $row->enabled && $row->min_tier === 'plus'))->toBeTrue();
});

test('the messaging types ship plus-only with whatsapp switched off', function () {
    $expected = [
        ['slug' => 'whatsapp', 'min_tier' => 'plus', 'sort_order' => 15, 'enabled' => false],
        ['slug' => 'google_chat', 'min_tier' => 'plus', 'sort_order' => 16, 'enabled' => true],
        ['slug' => 'mattermost', 'min_tier' => 'plus', 'sort_order' => 17, 'enabled' => true],
        ['slug' => 'rocketchat', 'min_tier' => 'plus', 'sort_order' => 18, 'enabled' => true],
        ['slug' => 'matrix', 'min_tier' => 'plus', 'sort_order' => 19, 'enabled' => true],
    ];
    $slugs = array_column($expected, 'slug');
    $migration = require database_path('migrations/2026_10_23_100001_add_messaging_channel_types.php');
    $rows = fn (): array => DB::table('channel_types')->whereIn('slug', $slugs)->orderBy('sort_order')->get(['slug', 'min_tier', 'sort_order', 'enabled'])
        ->map(fn (object $row): array => [
            'slug' => $row->slug, 'min_tier' => $row->min_tier, 'sort_order' => (int) $row->sort_order, 'enabled' => (bool) $row->enabled,
        ])->all();

    expect(array_values(collect(ChannelTypeSeeder::SHIPPED)->whereIn('slug', $slugs)->all()))->toBe($expected);

    $migration->down();
    expect($rows())->toBe([]);

    $migration->up();
    $migration->up();
    expect($rows())->toBe($expected);
});
