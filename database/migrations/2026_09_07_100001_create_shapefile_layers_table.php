<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shapefile_layers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('source_url');
            $table->string('last_seen_filename')->nullable();
            $table->string('last_seen_checksum')->nullable();
            $table->date('last_seen_effective_at')->nullable();
            $table->foreignId('active_version_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shapefile_layers');
    }
};
