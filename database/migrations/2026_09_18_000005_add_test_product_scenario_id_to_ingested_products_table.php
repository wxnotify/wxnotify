<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->foreignId('test_product_scenario_id')->nullable()->after('is_test')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('test_product_scenario_id');
        });
    }
};
