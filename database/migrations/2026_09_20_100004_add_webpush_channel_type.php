<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('channel_types')->where('slug', 'webpush')->exists()) {
            DB::table('channel_types')->insert([
                'slug' => 'webpush', 'enabled' => true, 'min_tier' => 'free', 'sort_order' => 9,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->where('slug', 'webpush')->delete();
    }
};
