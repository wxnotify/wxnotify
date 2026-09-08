<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('county_ugc')->nullable()->after('lng');
            $table->string('forecast_zone_ugc')->nullable()->after('county_ugc');
            $table->string('fire_weather_zone_ugc')->nullable()->after('forecast_zone_ugc');
            $table->string('timezone')->nullable()->after('fire_weather_zone_ugc');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['county_ugc', 'forecast_zone_ugc', 'fire_weather_zone_ugc', 'timezone']);
        });
    }
};
