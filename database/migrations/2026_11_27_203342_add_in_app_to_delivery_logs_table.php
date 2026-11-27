<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `in_app` marks a `mobile_push` row that was not sent (it stays `suppressed`) but is still shown in the app's
 * feed (DeliveryLog::IN_APP_FEED_SQL). Every existing row is false and there is deliberately no backfill: alerts
 * held before this change stay out of the app. A column with a constant default is a catalogue-only change on
 * PostgreSQL, so delivery_logs is not rewritten.
 *
 * It still needs a brief ACCESS EXCLUSIVE lock, and while ALTER TABLE waits for one behind a long transaction,
 * every later read and insert on delivery_logs queues behind it. So it waits at most 3 seconds and then fails
 * (SQLSTATE 55P03); nothing is changed or recorded, so run `migrate` again. SET LOCAL lasts only for the
 * migration's own transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '3s'");

        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->boolean('in_app')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropColumn('in_app');
        });
    }
};
