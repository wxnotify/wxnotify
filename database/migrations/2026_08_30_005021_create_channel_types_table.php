<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        DB::table('channel_types')->insert([
            ['slug' => 'email', 'min_tier' => 'free', 'sort_order' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'ntfy', 'min_tier' => 'free', 'sort_order' => 2, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'discord', 'min_tier' => 'free', 'sort_order' => 3, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'slack', 'min_tier' => 'free', 'sort_order' => 4, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'telegram', 'min_tier' => 'free', 'sort_order' => 5, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_types');
    }
};
