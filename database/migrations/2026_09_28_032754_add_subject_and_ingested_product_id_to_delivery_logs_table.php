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
        Schema::table('delivery_logs', function (Blueprint $table) {
            // The rendered title, so a subscriber-facing feed (e.g. the
            // dashboard's recent-alerts widget) has something to display
            // without re-deriving it from the rule/product. Set by both
            // DeliverWeatherAlert and DeliverScheduledAlert.
            $table->string('subject')->nullable()->after('error_detail');
            // Only ever set by DeliverWeatherAlert -- Threshold Alerts and
            // Daily Weather Summary (DeliverScheduledAlert) have no
            // IngestedProduct behind them at all, by design.
            $table->foreignId('ingested_product_id')->nullable()->after('subject')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ingested_product_id');
            $table->dropColumn('subject');
        });
    }
};
