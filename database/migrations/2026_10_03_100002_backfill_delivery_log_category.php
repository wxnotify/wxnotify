<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fills delivery_logs.category from each row's rule. This is its own
     * migration so the UPDATE runs in its own transaction: bundled with the
     * ADD COLUMN it would keep that statement's ACCESS EXCLUSIVE lock on
     * delivery_logs for the whole table rewrite, blocking alert inserts and reads.
     *
     * Safe to re-run (for example after a deploy where old queue workers wrote
     * uncategorised rows): the backfill is authoritative for rows that have a
     * rule, and IS DISTINCT FROM keeps a re-run from touching unchanged rows.
     * Rows whose rule is already gone cannot be recovered and stay null ("Other").
     */
    public function up(): void
    {
        DB::statement('
            UPDATE delivery_logs
            SET category = alert_rules.category
            FROM alert_rules
            WHERE delivery_logs.alert_rule_id = alert_rules.id
              AND delivery_logs.category IS DISTINCT FROM alert_rules.category
        ');
    }

    public function down(): void
    {
        // Intentionally a no-op: the column is dropped by the migration that adds it.
    }
};
