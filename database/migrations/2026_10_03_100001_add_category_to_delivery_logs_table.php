<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
     * Guarded so it is safe to run when the column already exists. Existing rows
     * are filled by the following backfill migration, kept separate so its
     * full-table UPDATE does not run under this migration's schema lock.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('delivery_logs', 'category')) {
            Schema::table('delivery_logs', function (Blueprint $table) {
                $table->string('category')->nullable()->after('status');
                $table->index(['user_id', 'status', 'dispatched_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'dispatched_at']);
            $table->dropColumn('category');
        });
    }
};
