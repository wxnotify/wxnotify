<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // The phone that keeps this location's position current. A location is never deleted
            // with its phone (alert_rule_location cascades), so the link just goes null.
            $table->foreignId('app_token_id')->nullable()->unique()
                ->constrained('personal_access_tokens')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_token_id');
        });
    }
};
