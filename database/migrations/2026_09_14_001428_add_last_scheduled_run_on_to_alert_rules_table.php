<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Threshold Alerts and Daily Weather Summary both evaluate/deliver once per
 * calendar day, at the rule's own delivery_time in its location's timezone
 * — this tracks the local date that already happened for, so the scheduled
 * command (running every 15 minutes) doesn't re-dispatch on every tick that
 * falls within the same day's delivery window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->date('last_scheduled_run_on')->nullable()->after('delivery_time');
        });
    }

    public function down(): void
    {
        Schema::table('alert_rules', function (Blueprint $table) {
            $table->dropColumn('last_scheduled_run_on');
        });
    }
};
