<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('notification_channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel_type');
            $table->string('status');
            $table->text('error_detail')->nullable();
            $table->timestamp('dispatched_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_logs');
    }
};
