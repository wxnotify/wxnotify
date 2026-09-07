<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_record_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shapefile_layer_id')->constrained()->cascadeOnDelete();
            $table->string('record_key');
            $table->string('field');
            $table->string('value');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['shapefile_layer_id', 'record_key', 'field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_record_overrides');
    }
};
