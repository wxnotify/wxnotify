<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([['pushover', 6], ['msteams', 7]] as [$slug, $order]) {
            if (! DB::table('channel_types')->where('slug', $slug)->exists()) {
                DB::table('channel_types')->insert([
                    'slug' => $slug, 'enabled' => true, 'min_tier' => 'free', 'sort_order' => $order,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('channel_types')->whereIn('slug', ['pushover', 'msteams'])->delete();
    }
};
