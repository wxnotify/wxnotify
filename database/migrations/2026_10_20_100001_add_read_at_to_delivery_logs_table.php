<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read state for the mobile app's alerts: `read_at` on a successful `mobile_push` delivery row (shared by every
 * phone of the subscriber). An alert is unread while any successful push row of its group has no `read_at`.
 *
 * Runs outside a transaction so nothing holds a lock on delivery_logs for long: the nullable column without a
 * default is a catalogue-only change that commits at once, each backfill batch commits on its own, and the
 * index is built CONCURRENTLY (alert inserts keep flowing). Rows written by old code between this migration and
 * the code deploy start unread, which is acceptable.
 *
 * The index is partial on the unread successful pushes, so it stays tiny (only what is still unread) and serves
 * the unread count (CountUnreadAppAlerts) and marking everything read; its columns carry the group key so the
 * count is an index-only scan.
 */
return new class extends Migration
{
    /**
     * Ids per backfill UPDATE: small enough that each statement locks only a few thousand rows briefly.
     */
    public const BACKFILL_CHUNK_SIZE = 5000;

    public $withinTransaction = false;

    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable();
        });

        $this->backfill();

        DB::statement("
            CREATE INDEX CONCURRENTLY IF NOT EXISTS delivery_logs_unread_push_index
            ON delivery_logs (user_id, ingested_product_id, alert_rule_id)
            WHERE channel_type = 'mobile_push' AND status = 'success' AND read_at IS NULL
        ");
    }

    /**
     * Every existing successful push starts read (at its creation time), one id range at a time, so no single
     * statement locks the whole table. Safe to run again: rows already read are left alone.
     */
    public function backfill(int $chunkSize = self::BACKFILL_CHUNK_SIZE): void
    {
        $bounds = DB::table('delivery_logs')
            ->where('channel_type', 'mobile_push')
            ->where('status', 'success')
            ->whereNull('read_at')
            ->selectRaw('MIN(id) AS first_id, MAX(id) AS last_id')
            ->first();

        if ($bounds->first_id === null) {
            return;
        }

        for ($from = (int) $bounds->first_id; $from <= (int) $bounds->last_id; $from += $chunkSize) {
            DB::table('delivery_logs')
                ->whereBetween('id', [$from, $from + $chunkSize - 1])
                ->where('channel_type', 'mobile_push')
                ->where('status', 'success')
                ->whereNull('read_at')
                ->update(['read_at' => DB::raw('COALESCE(created_at, dispatched_at)')]);
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS delivery_logs_unread_push_index');

        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
