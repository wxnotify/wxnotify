<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ZONE_TYPES = ['coastal', 'offshore', 'high_seas'];

    public function up(): void
    {
        foreach (self::ZONE_TYPES as $zoneType) {
            foreach (["marine_zones_{$zoneType}", "marine_zones_{$zoneType}_staging"] as $table) {
                Schema::create($table, function (Blueprint $t) {
                    $t->id();
                    $t->string('zone_type', 20);
                    $t->string('zone_id', 6)->unique();
                    $t->string('wfo', 3)->nullable();
                    $t->string('gl_wfo', 3)->nullable();
                    $t->string('name', 250);
                    $t->string('ajoin0', 5)->nullable();
                    $t->string('ajoin1', 5)->nullable();
                    $t->decimal('lon', 12, 7);
                    $t->decimal('lat', 12, 7);
                    $t->geometry('boundary', 'MULTIPOLYGON', 4269);
                    $t->timestamps();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::ZONE_TYPES as $zoneType) {
            Schema::dropIfExists("marine_zones_{$zoneType}_staging");
            Schema::dropIfExists("marine_zones_{$zoneType}");
        }
    }
};
