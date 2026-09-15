<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->text('hazard')->nullable()->after('wmo_identifier');
            // The bulletin's own SOURCE...bullet (e.g. "Radar indicated") --
            // named hazard_source, not source, specifically to avoid
            // confusion with active_wwas.source (an unrelated text/cap
            // provenance marker on a different table).
            $table->text('hazard_source')->nullable()->after('hazard');
            $table->text('impact')->nullable()->after('hazard_source');
            $table->text('locations_impacted')->nullable()->after('impact');
            $table->text('call_to_action')->nullable()->after('locations_impacted');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn(['hazard', 'hazard_source', 'impact', 'locations_impacted', 'call_to_action']);
        });
    }
};
