<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ChannelTypeSeeder extends Seeder
{
    /**
     * The shipped channel-type catalog; shared by the create_channel_types
     * migration and the Reset to Factory Defaults catalog reset.
     *
     * @var list<array{slug: string, min_tier: string, sort_order: int, enabled: bool}>
     */
    public const SHIPPED = [
        ['slug' => 'email', 'min_tier' => 'free', 'sort_order' => 1, 'enabled' => true],
        ['slug' => 'ntfy', 'min_tier' => 'free', 'sort_order' => 2, 'enabled' => true],
        ['slug' => 'discord', 'min_tier' => 'free', 'sort_order' => 3, 'enabled' => true],
        ['slug' => 'slack', 'min_tier' => 'free', 'sort_order' => 4, 'enabled' => true],
        ['slug' => 'telegram', 'min_tier' => 'free', 'sort_order' => 5, 'enabled' => true],
    ];

    public function run(): void
    {
        foreach (self::SHIPPED as $row) {
            $exists = DB::table('channel_types')->where('slug', $row['slug'])->exists();

            if ($exists) {
                DB::table('channel_types')->where('slug', $row['slug'])->update($row + ['updated_at' => now()]);
            } else {
                DB::table('channel_types')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            }
        }
    }
}
