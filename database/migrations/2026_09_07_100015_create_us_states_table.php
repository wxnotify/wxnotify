<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['us_states', 'us_states_staging'] as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('state', 2)->unique();
                $t->string('name', 24);
                $t->string('fips', 2)->nullable();
                $t->decimal('lon', 12, 7);
                $t->decimal('lat', 12, 7);
                $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('us_states_staging');
        Schema::dropIfExists('us_states');
    }
};
