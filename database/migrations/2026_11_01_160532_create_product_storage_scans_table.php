<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_storage_scans', function (Blueprint $table) {
            $table->id();
            $table->string('status');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->bigInteger('nwws_bytes')->default(0);
            $table->bigInteger('emwin_byteblaster_bytes')->default(0);
            $table->bigInteger('emwin_http_bytes')->default(0);
            $table->unsignedBigInteger('file_count')->default(0);
            $table->timestamp('oldest_file_at')->nullable();
            $table->json('disks')->nullable();
            $table->timestamps();

            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_storage_scans');
    }
};
