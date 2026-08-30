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
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->string('name')->nullable();

            // Weather Alerts / SPC & WPC Alerts
            $table->string('matching_mode')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('wfo')->nullable();
            $table->string('county_zone_codes')->nullable();
            $table->json('risk_levels')->nullable();

            // Threshold Alerts
            $table->string('variable')->nullable();
            $table->string('direction')->nullable();
            $table->decimal('threshold_value', 6, 1)->nullable();
            $table->string('timeframe')->nullable();

            // Threshold Alerts / Daily Weather Summary
            $table->time('delivery_time')->nullable();

            // Lightning Alerts
            $table->unsignedInteger('radius_miles')->nullable();

            // Every category
            $table->time('quiet_hours_start')->nullable();
            $table->time('quiet_hours_end')->nullable();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
