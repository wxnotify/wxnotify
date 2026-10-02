<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Forecast Discussion is no longer its own alert category: AFD is an
     * ordinary Weather Alerts product (Other Forecasts, Outlooks,
     * Discussions, and Reports). Every existing forecast_discussion rule
     * becomes the equivalent Weather Alerts rule -- AFD attached, matched by
     * WFO -- so its subscriber keeps receiving the same discussions once the
     * matching engine stops special-casing the old category.
     */
    public function up(): void
    {
        $afdId = DB::table('products')->where('code', 'AFD')->value('id');

        // A catalog without AFD can't be attached to, but the rows still
        // have to leave the removed category, or loading one would throw.
        if ($afdId !== null) {
            DB::statement("
                INSERT INTO alert_rule_product (alert_rule_id, product_id)
                SELECT id, ? FROM alert_rules
                WHERE category = 'forecast_discussion'
            ", [$afdId]);
        }

        DB::table('alert_rules')
            ->where('category', 'forecast_discussion')
            ->update(['category' => 'weather_alerts', 'matching_mode' => 'wfo']);
    }

    /**
     * Not reversible: once converted, a Weather Alerts rule that happens to
     * be WFO-matched with AFD attached is indistinguishable from one the
     * subscriber built that way on purpose.
     */
    public function down(): void {}
};
