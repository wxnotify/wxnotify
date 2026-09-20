<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->text('apprise_url')->nullable()->change();
            $table->text('config')->nullable()->after('apprise_url');
        });
    }

    public function down(): void
    {
        // Restoring NOT NULL needs no null rows, but config-only channels
        // (webhook / web push) legitimately have no Apprise URL, and their
        // config column holds the only copy of the subscriber's endpoint URL,
        // signing secret or push subscription. Deleting them to make the
        // column NOT NULL again would destroy that irreversibly — and all four
        // channel migrations land in one batch, so a plain
        // `migrate:rollback` would trip over it silently. Refuse instead.
        $configOnly = DB::table('notification_channels')->whereNull('apprise_url')->count();

        if ($configOnly > 0) {
            throw new RuntimeException(
                "Rolling this migration back would delete {$configOnly} config-only channels (webhook / web push); remove or migrate them first."
            );
        }

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn('config');
            $table->text('apprise_url')->nullable(false)->change();
        });
    }
};
