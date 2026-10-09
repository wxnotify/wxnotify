<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which events of a bulletin a delivery covered.
        Schema::create('delivery_log_segment', function (Blueprint $table) {
            $table->foreignId('delivery_log_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingested_product_segment_id')->constrained()->cascadeOnDelete();

            $table->primary(['delivery_log_id', 'ingested_product_segment_id']);
            $table->index('ingested_product_segment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_log_segment');
    }
};
