<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Bluesky channel type (Bluesky-hosted accounts only), Plus and up, shipped switched on
 * like the chat types (ChannelTypeSeeder::SHIPPED carries the same row).
 */
return new class extends Migration
{
    private const SLUG = 'bluesky';

    public function up(): void
    {
        if (! DB::table('channel_types')->where('slug', self::SLUG)->exists()) {
            DB::table('channel_types')->insert([
                'slug' => self::SLUG, 'min_tier' => 'plus', 'sort_order' => 21, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->where('slug', self::SLUG)->delete();
    }
};
