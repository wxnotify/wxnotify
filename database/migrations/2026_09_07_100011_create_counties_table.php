<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['counties', 'counties_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('cwa', 9);
                $t->string('countyname', 24);
                $t->string('fips', 5)->unique();
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('counties_staging');
        Schema::dropIfExists('counties');
    }
};
