<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cwa_boundaries', 'cwa_boundaries_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('wfo', 3);
                $t->string('cwa', 3)->unique();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->string('region', 2)->nullable();
                $t->string('fullstaid', 4)->nullable();
                $t->string('citystate', 50)->nullable();
                $t->string('city', 50)->nullable();
                $t->string('state', 50)->nullable();
                $t->string('st', 2)->nullable();
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cwa_boundaries_staging');
        Schema::dropIfExists('cwa_boundaries');
    }
};
