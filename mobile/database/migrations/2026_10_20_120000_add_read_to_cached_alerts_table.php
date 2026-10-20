<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The read flag of each cached alert, so the Alerts list shows read and unread offline. Runs on the phone
 * at the next app start: rows cached by an older build take the default and count as read until the
 * next feed load brings the server's flag (the server starts every existing alert read too).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cached_alerts', function (Blueprint $table) {
            $table->boolean('read')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('cached_alerts', function (Blueprint $table) {
            $table->dropColumn('read');
        });
    }
};
