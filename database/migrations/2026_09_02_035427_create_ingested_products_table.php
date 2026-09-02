<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingested_products', function (Blueprint $table) {
            $table->id();
            $table->string('cccc')->nullable()->index();
            $table->string('ttaaii')->nullable()->index();
            $table->string('awipsid')->nullable()->index();
            $table->string('nwws_id')->nullable();
            $table->string('wmo_ddhhmm', 6)->nullable();
            $table->text('text');
            $table->string('file_path')->unique();
            $table->timestamp('received_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingested_products');
    }
};
