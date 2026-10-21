<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The SMS channel types, Plus and up, shipped switched off until the owner's legal review
 * of the consent text (ChannelTypeSeeder::SHIPPED carries the same rows).
 */
return new class extends Migration
{
    /** @var list<array{slug: string, sort_order: int}> */
    private const ROWS = [
        ['slug' => 'twilio_sms', 'sort_order' => 11],
        ['slug' => 'bulkvs_sms', 'sort_order' => 12],
        ['slug' => 'httpsms', 'sort_order' => 13],
    ];

    public function up(): void
    {
        foreach (self::ROWS as $row) {
            if (! DB::table('channel_types')->where('slug', $row['slug'])->exists()) {
                DB::table('channel_types')->insert([
                    ...$row, 'enabled' => false, 'min_tier' => 'plus',
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
