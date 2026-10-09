<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_product_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ingested_product_id')->constrained()->cascadeOnDelete();
            // Bulletin order: sections top to bottom, and within a section its VTEC lines top to bottom.
            $table->unsignedSmallInteger('position');
            // The same five columns as ActiveWwa::vtecKeyFor(), plus the action and times. Nullable: a
            // fallback segment copies a bulletin's own columns, which can be partly empty.
            $table->string('vtec_office')->nullable();
            $table->string('vtec_phenomenon')->nullable();
            $table->string('vtec_significance')->nullable();
            $table->string('vtec_event_number', 4)->nullable();
            $table->smallInteger('vtec_year')->nullable();
            $table->string('vtec_action')->nullable();
            $table->timestamp('vtec_begin')->nullable();
            $table->timestamp('vtec_end')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('ugc_codes')->nullable();
            $table->json('polygon')->nullable();
            $table->timestamps();

            $table->index(['ingested_product_id', 'position']);
            $table->index(
                ['vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year'],
                'ingested_product_segments_vtec_key_index',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_product_segments');
    }
};
