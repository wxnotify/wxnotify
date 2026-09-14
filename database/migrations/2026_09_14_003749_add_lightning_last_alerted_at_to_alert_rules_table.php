<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightning Alerts match against a continuous stream of flash events, not a
 * single discrete product — without a cooldown, one active storm overhead
 * would re-fire the same rule every ~30-60s poll cycle for as long as it
 * kept producing flashes. This tracks when a rule last actually alerted, so
 * MatchLightningFlashes can suppress re-firing within the cooldown window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->timestamp('lightning_last_alerted_at')->nullable()->after('radius_miles');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('lightning_last_alerted_at');
        });
    }
};
