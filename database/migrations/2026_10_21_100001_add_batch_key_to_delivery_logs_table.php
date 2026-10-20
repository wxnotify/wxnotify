<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `batch_key` ties together the delivery rows of one alert that has no product: one scheduled or lightning run of
 * one rule, sent to each of the rule's channels (DeliverScheduledAlert). The app's feed groups such rows into one
 * alert (ListAppAlerts::GROUP_KEY), so a summary pushed to two phones is one alert. Old rows keep NULL and stay an
 * alert each, as before; there is no backfill.
 *
 * Runs outside a transaction so nothing holds a lock on delivery_logs for long: the nullable column without a
 * default is a catalogue-only change that commits at once, and the index is built CONCURRENTLY (alert inserts keep
 * flowing). The index is partial on rows that have a key, so it starts empty and only covers new no-product
 * deliveries; it serves the lookups of one user's batch (FindAppAlert, ListAppAlerts, PushBadgeCount).
 *
 * Safe to run again after an interruption: the column is added only when missing, and the index is dropped and
 * rebuilt, because an interrupted CONCURRENTLY build leaves an INVALID index that `IF NOT EXISTS` would accept.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('delivery_logs', 'batch_key')) {
            Schema::table('delivery_logs', function (Blueprint $table) {
                $table->string('batch_key', 36)->nullable();
            });
        }

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_batch_key_index');

        DB::statement('
            CREATE INDEX CONCURRENTLY delivery_logs_batch_key_index
            ON delivery_logs (user_id, batch_key)
            WHERE batch_key IS NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_batch_key_index');

        if (Schema::hasColumn('delivery_logs', 'batch_key')) {
            Schema::table('delivery_logs', function (Blueprint $table) {
                $table->dropColumn('batch_key');
            });
        }
    }
};
