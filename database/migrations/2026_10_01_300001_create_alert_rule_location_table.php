<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rule_location', function (Blueprint $table) {
            $table->foreignId('alert_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->primary(['alert_rule_id', 'location_id']);
        });

        // Backfill: every existing Location/Polygon/RiskArea-mode Weather
        // Alerts/SPC & WPC rule must keep matching after this ships, or its
        // subscriber silently stops receiving alerts the moment the
        // matching engine switches from reading location_id to reading
        // this pivot (see the design spec's "Decisions" section).
        DB::statement("
            INSERT INTO alert_rule_location (alert_rule_id, location_id)
            SELECT id, location_id FROM alert_rules
            WHERE category IN ('weather_alerts', 'spc_wpc')
            AND matching_mode IN ('location', 'polygon', 'risk_area')
            AND location_id IS NOT NULL
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rule_location');
    }
};
