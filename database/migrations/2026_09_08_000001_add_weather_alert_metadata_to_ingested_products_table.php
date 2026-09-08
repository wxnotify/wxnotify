<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->foreignId('product_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->json('ugc_codes')->nullable()->after('text');
            $table->json('polygon')->nullable()->after('ugc_codes');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['ugc_codes', 'polygon']);
        });
    }
};
