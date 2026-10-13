<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->string('matching_mode')->nullable();
            $table->string('wfo')->nullable();
            $table->text('county_zone_codes')->nullable();
        });

        Schema::create('alert_rule_product_location', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'product_id', 'location_id']);
        });

        $this->backfill();
    }

    /**
     * Every product of a product-based rule takes the rule's own mode, WFO, zones and locations, so what a
     * subscriber receives does not change at deploy.
     */
    public function backfill(): void
    {
        DB::statement("
            UPDATE alert_rule_product AS arp
            SET matching_mode = ar.matching_mode, wfo = ar.wfo, county_zone_codes = ar.county_zone_codes
            FROM alert_rules AS ar
            WHERE ar.id = arp.alert_rule_id
              AND ar.category IN ('weather_alerts', 'spc_wpc')
              AND ar.matching_mode IS NOT NULL
        ");

        DB::statement('
            INSERT INTO alert_rule_product_location (alert_rule_id, product_id, location_id)
            SELECT arp.alert_rule_id, arp.product_id, arl.location_id
            FROM alert_rule_product AS arp
            JOIN alert_rule_location AS arl ON arl.alert_rule_id = arp.alert_rule_id
            WHERE arp.matching_mode IS NOT NULL
            ON CONFLICT DO NOTHING
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rule_product_location');

        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->dropColumn(['matching_mode', 'wfo', 'county_zone_codes']);
        });
    }
};
