<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The unread index follows the app's feed, which now also holds marked (`in_app`) rows: its predicate is
 * DeliveryLog::IN_APP_FEED_SQL plus `read_at IS NULL`, spelled out here so a later change to that constant
 * cannot rewrite this migration. It replaces `delivery_logs_unread_push_index` and still serves code that asks
 * only for successful pushes (their condition implies this one), so workers not yet updated keep an index.
 *
 * Runs outside a transaction and builds and drops CONCURRENTLY, so alert inserts keep flowing. The new index is
 * built before the old one is dropped. Safe to run again after an interruption: the index being built is dropped
 * first, because an interrupted CONCURRENTLY build leaves an INVALID index that `IF NOT EXISTS` would accept.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_app_unread_index');

        DB::statement("
            CREATE INDEX CONCURRENTLY delivery_logs_app_unread_index
            ON delivery_logs (user_id, ingested_product_id, alert_rule_id)
            WHERE channel_type = 'mobile_push' AND (status = 'success' OR in_app) AND read_at IS NULL
        ");

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_unread_push_index');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_unread_push_index');

        DB::statement("
            CREATE INDEX CONCURRENTLY delivery_logs_unread_push_index
            ON delivery_logs (user_id, ingested_product_id, alert_rule_id)
            WHERE channel_type = 'mobile_push' AND status = 'success' AND read_at IS NULL
        ");

        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_app_unread_index');
    }
};
