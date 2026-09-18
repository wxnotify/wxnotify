<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('has_vtec')->default(false)->after('has_polygon');
            $table->boolean('has_ugc')->default(false)->after('has_vtec');
            $table->json('damage_tag_options')->nullable()->after('risk_level_options');
        });

        // Backfill for the real seeded catalog (see the products table
        // migration): Warning/Watch/Advisory always carry VTEC and UGC.
        // Statement-family is mixed -- SVS is a VTEC-warning continuation,
        // SPS is not -- so it's excluded from the blanket update and set
        // explicitly per code below.
        DB::table('products')->whereIn('family', ['warning', 'watch', 'advisory'])
            ->update(['has_vtec' => true, 'has_ugc' => true]);

        DB::table('products')->where('code', 'SVS')->update(['has_vtec' => true, 'has_ugc' => true]);
        DB::table('products')->where('code', 'SPS')->update(['has_vtec' => false, 'has_ugc' => true]);

        DB::table('products')->where('code', 'SV.W')->update([
            'damage_tag_options' => json_encode([
                'HAIL THREAT...RADAR INDICATED',
                'HAIL THREAT...OBSERVED',
                'MAX HAIL SIZE...<.75 IN',
                'MAX HAIL SIZE...1.00 IN',
                'MAX HAIL SIZE...1.75 IN',
                'WIND THREAT...RADAR INDICATED',
                'WIND THREAT...OBSERVED',
                'MAX WIND GUST...60 MPH',
                'MAX WIND GUST...70 MPH',
                'THUNDERSTORM DAMAGE THREAT...CONSIDERABLE',
                'THUNDERSTORM DAMAGE THREAT...DESTRUCTIVE',
            ]),
        ]);

        DB::table('products')->where('code', 'TO.W')->update([
            'damage_tag_options' => json_encode([
                'TORNADO...RADAR INDICATED',
                'TORNADO...OBSERVED',
                'TORNADO DAMAGE THREAT...CONSIDERABLE',
                'TORNADO DAMAGE THREAT...CATASTROPHIC',
                'FLASH FLOOD...RADAR INDICATED',
            ]),
        ]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['has_vtec', 'has_ugc', 'damage_tag_options']);
        });
    }
};
