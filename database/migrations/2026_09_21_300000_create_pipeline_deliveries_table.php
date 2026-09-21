<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('feed');
            $table->string('wmo_header_key')->nullable()->index();
            $table->string('file_name');
            $table->string('outcome');
            $table->timestamp('received_at');
            $table->index(['feed', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_deliveries');
    }
};
