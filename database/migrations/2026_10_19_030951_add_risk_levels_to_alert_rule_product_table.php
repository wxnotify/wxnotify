<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves an SPC & WPC rule's selected risk levels from the rule (one flat list of level names, shared by every
 * product, so "Moderate" ticked for the Day 1 Convective Outlook also counted for the Excessive Rainfall
 * Outlook) onto each product it watches.
 *
 * Rollback: down() only drops the column. `alert_rules.risk_levels` keeps being written (the union of the
 * products' levels), so the code before this migration reads every level the subscriber ticked, coupled by
 * name across products as it always was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->json('risk_levels')->nullable();
        });

        $this->backfill();
    }

    /**
     * Each risk-level product of an SPC & WPC rule takes the rule's levels that it offers itself, in option
     * order (exactly what matching counted for it before), or an empty list (every level). A row that already
     * holds levels, a product without risk levels and every other category are left alone, so it can run
     * again safely.
     */
    public function backfill(): void
    {
        DB::statement("
            UPDATE alert_rule_product AS arp
            SET risk_levels = (
                SELECT coalesce(json_agg(option.level ORDER BY option.position), '[]'::json)
                FROM json_array_elements_text(p.risk_level_options) WITH ORDINALITY AS option(level, position)
                WHERE json_typeof(ar.risk_levels) = 'array'
                  AND option.level IN (SELECT json_array_elements_text(ar.risk_levels))
            )
            FROM alert_rules AS ar, products AS p
            WHERE ar.id = arp.alert_rule_id
              AND p.id = arp.product_id
              AND ar.category = 'spc_wpc'
              AND arp.risk_levels IS NULL
              AND json_typeof(p.risk_level_options) = 'array'
        ");
    }

    public function down(): void
    {
        Schema::table('alert_rule_product', function (Blueprint $table) {
            $table->dropColumn('risk_levels');
        });
    }
};
