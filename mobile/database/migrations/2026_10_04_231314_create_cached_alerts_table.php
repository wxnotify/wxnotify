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
        Schema::create('cached_alerts', function (Blueprint $table) {
            $table->unsignedBigInteger('alert_id')->primary();
            $table->timestamp('sort_at')->index();
            $table->json('list');
            $table->json('detail')->nullable();
            $table->boolean('in_feed')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cached_alerts');
    }
};
