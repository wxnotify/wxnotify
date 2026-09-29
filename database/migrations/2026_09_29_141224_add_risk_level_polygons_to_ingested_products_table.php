<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            // One ring per named risk level (Marginal/Slight/Enhanced/
            // Moderate/High), keyed by label -- see
            // ExtractProductMetadata::extractCategoricalRiskAreas(). Only
            // ever populated for the SPC Convective Outlook, from its
            // separate PTSDY1 bulletin; `polygon` (the single-ring column)
            // stays what every other product uses.
            $table->json('risk_level_polygons')->nullable()->after('risk_levels');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn('risk_level_polygons');
        });
    }
};
