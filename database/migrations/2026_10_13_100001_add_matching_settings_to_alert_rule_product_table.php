<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves how a product-based rule matches (mode, WFO, county/zone codes, locations) from the rule onto each
 * of its products.
 *
 * Deploy note: roll this migration back together with the code, never the code alone. A rule saved after
 * this deploy has its own matching_mode, wfo, county_zone_codes and alert_rule_location rows nulled, and
 * only down() (restoreRuleColumns()) copies them back from the per-product rows.
 */
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

    /**
     * Gives a rule that holds no mode of its own (saved after the deploy) the settings of its product
     * rows again, so the code before this migration reads what the subscriber chose. The rule takes the
     * mode, WFO and zones of its lowest-product-id row that has a mode, and the union of every location
     * its products hold. A rule whose products differ (a mixed rule) degrades to that one mode for all
     * its products. A rule that still holds its own mode, a rule whose rows all inherit, and any other
     * category are left alone.
     */
    public function restoreRuleColumns(): void
    {
        DB::statement("
            INSERT INTO alert_rule_location (alert_rule_id, location_id)
            SELECT DISTINCT arpl.alert_rule_id, arpl.location_id
            FROM alert_rule_product_location AS arpl
            JOIN alert_rules AS ar ON ar.id = arpl.alert_rule_id
            WHERE ar.category IN ('weather_alerts', 'spc_wpc')
              AND ar.matching_mode IS NULL
              AND EXISTS (
                  SELECT 1 FROM alert_rule_product AS arp
                  WHERE arp.alert_rule_id = ar.id AND arp.matching_mode IS NOT NULL
              )
            ON CONFLICT DO NOTHING
        ");

        DB::statement("
            UPDATE alert_rules AS ar
            SET matching_mode = src.matching_mode, wfo = src.wfo, county_zone_codes = src.county_zone_codes
            FROM (
                SELECT DISTINCT ON (alert_rule_id) alert_rule_id, matching_mode, wfo, county_zone_codes
                FROM alert_rule_product
                WHERE matching_mode IS NOT NULL
                ORDER BY alert_rule_id, product_id
            ) AS src
            WHERE src.alert_rule_id = ar.id
              AND ar.category IN ('weather_alerts', 'spc_wpc')
              AND ar.matching_mode IS NULL
        ");
    }

    public function down(): void
    {
        $this->restoreRuleColumns();

        Schema::dropIfExists('alert_rule_product_location');

        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->dropColumn(['matching_mode', 'wfo', 'county_zone_codes']);
        });
    }
};
