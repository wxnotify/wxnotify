<?php

use Database\Seeders\ChannelTypeSeeder;
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
        Schema::create('channel_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('enabled')->default(true);
            $table->string('min_tier')->default('free');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        (new ChannelTypeSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_types');
    }
};
