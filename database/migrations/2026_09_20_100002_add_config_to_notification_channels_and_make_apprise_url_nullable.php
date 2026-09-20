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
        // Restoring NOT NULL requires no null rows, so channels that have no
        // Apprise URL (config-only channels) are removed first.
        DB::table('notification_channels')->whereNull('apprise_url')->delete();

        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropColumn('config');
            $table->text('apprise_url')->nullable(false)->change();
        });
    }
};
