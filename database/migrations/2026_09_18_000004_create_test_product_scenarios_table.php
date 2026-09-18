<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_product_scenarios', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->foreignId('product_id')->constrained();
            $table->foreignId('continues_scenario_id')->nullable()->constrained('test_product_scenarios')->nullOnDelete();
            $table->json('input');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_product_scenarios');
    }
};
