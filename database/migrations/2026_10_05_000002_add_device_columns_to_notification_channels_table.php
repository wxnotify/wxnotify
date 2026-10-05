<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            // A device channel lives and dies with the app token that registered it.
            $table->foreignId('personal_access_token_id')->nullable()->unique()
                ->constrained('personal_access_tokens')->cascadeOnDelete();
            // The FCM token itself is encrypted inside `config`; its hash makes "who holds this token" a query.
            $table->string('device_token_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('notification_channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('personal_access_token_id');
            $table->dropColumn('device_token_hash');
        });
    }
};
