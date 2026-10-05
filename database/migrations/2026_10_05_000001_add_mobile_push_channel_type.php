<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('channel_types')->where('slug', 'mobile_push')->exists()) {
            DB::table('channel_types')->insert([
                'slug' => 'mobile_push', 'enabled' => true, 'min_tier' => 'free', 'sort_order' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->where('slug', 'mobile_push')->delete();
    }
};
