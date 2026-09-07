<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['public_zones', 'public_zones_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2);
                $t->string('cwa', 9);
                $t->string('time_zone', 2)->nullable();
                $t->string('fe_area', 2)->nullable();
                $t->string('zone', 3);
                $t->string('name', 254);
                $t->string('state_zone', 5)->unique();
                $t->string('shortname', 32)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('public_zones_staging');
        Schema::dropIfExists('public_zones');
    }
};
