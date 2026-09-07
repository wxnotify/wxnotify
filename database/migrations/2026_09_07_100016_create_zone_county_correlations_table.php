<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['zone_county_correlations', 'zone_county_correlations_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('zone', 3);
                $t->string('cwa', 3);
                $t->string('name', 254);
                $t->string('state_zone', 5);
                $t->string('county', 100);
                $t->string('fips', 5);
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->decimal('lat', 12, 7);
                $t->decimal('lon', 12, 7);
                $t->timestamps();

                $t->unique(['state_zone', 'fips']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zone_county_correlations_staging');
        Schema::dropIfExists('zone_county_correlations');
    }
};
