<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_layer_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shapefile_layer_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('checksum')->nullable();
            $table->string('source_url');
            $table->date('effective_at');
            $table->string('status');
            $table->unsignedInteger('record_count')->nullable();
            $table->json('diff_summary')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_layer_versions');
    }
};
