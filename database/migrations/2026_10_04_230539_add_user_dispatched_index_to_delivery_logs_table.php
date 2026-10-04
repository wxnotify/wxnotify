<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * delivery_logs is a large, constantly written table. CREATE INDEX CONCURRENTLY (->online())
     * builds the index without blocking those writes, but Postgres refuses it inside a transaction.
     */
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->index(['user_id', 'dispatched_at'])->online();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'dispatched_at']);
        });
    }
};
