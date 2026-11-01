<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_storage_buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_storage_scan_id')->constrained()->cascadeOnDelete();
            $table->string('dimension');
            $table->string('label');
            $table->bigInteger('bytes');
            $table->unsignedBigInteger('file_count');
            $table->timestamps();

            $table->index(['product_storage_scan_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_storage_buckets');
    }
};
