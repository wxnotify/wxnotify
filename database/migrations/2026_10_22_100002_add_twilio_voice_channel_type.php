<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Twilio voice call channel type, Plus and up, shipped switched off until the owner's legal
 * review of the consent text (ChannelTypeSeeder::SHIPPED carries the same row).
 */
return new class extends Migration
{
    private const SLUG = 'twilio_voice';

    public function up(): void
    {
        if (! DB::table('channel_types')->where('slug', self::SLUG)->exists()) {
            DB::table('channel_types')->insert([
                'slug' => self::SLUG, 'sort_order' => 14, 'enabled' => false, 'min_tier' => 'plus',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->where('slug', self::SLUG)->delete();
    }
};
