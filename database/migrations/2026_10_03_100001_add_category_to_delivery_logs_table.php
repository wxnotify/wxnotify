<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * delivery_logs.alert_rule_id is nulled when a rule is deleted, which would
     * make a per-category history lose every row whose rule is gone. Recording
     * the category on the row itself keeps the dashboard's category breakdown
     * accurate. The index serves the dashboard charts' filter exactly
     * (this subscriber, successful rows, a date range).
     *
     * Idempotent on purpose: the schema change is guarded and the backfill runs
     * every time, so a test can re-run it against rows it has just inserted.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('delivery_logs', 'category')) {
            Schema::table('delivery_logs', function (Blueprint $table) {
                $table->string('category')->nullable()->after('status');
                $table->index(['user_id', 'status', 'dispatched_at']);
            });
        }

        // Rows whose rule is already gone cannot be recovered and stay null ("Other").
        DB::statement('
            UPDATE delivery_logs
            SET category = alert_rules.category
            FROM alert_rules
            WHERE delivery_logs.alert_rule_id = alert_rules.id
        ');
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'dispatched_at']);
            $table->dropColumn('category');
        });
    }
};
