<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The messaging channel types, Plus and up. WhatsApp ships switched off until the owner's Meta
 * and legal items are done; the four chat types ship on (ChannelTypeSeeder::SHIPPED carries the
 * same rows). A row whose class is not registered yet stays out of the picker.
 */
return new class extends Migration
{
    /** @var list<array{slug: string, sort_order: int, enabled: bool}> */
    private const ROWS = [
        ['slug' => 'whatsapp', 'sort_order' => 15, 'enabled' => false],
        ['slug' => 'google_chat', 'sort_order' => 16, 'enabled' => true],
        ['slug' => 'mattermost', 'sort_order' => 17, 'enabled' => true],
        ['slug' => 'rocketchat', 'sort_order' => 18, 'enabled' => true],
        ['slug' => 'matrix', 'sort_order' => 19, 'enabled' => true],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            if (! DB::table('channel_types')->where('slug', $row['slug'])->exists()) {
                DB::table('channel_types')->insert([
                    ...$row, 'min_tier' => 'plus',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->whereIn('slug', array_column(self::ROWS, 'slug'))->delete();
    }
};
