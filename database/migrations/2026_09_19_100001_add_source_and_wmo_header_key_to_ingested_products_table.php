<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->string('source')->default('nwws')->after('file_path');
            $table->string('wmo_header_key')->nullable()->after('source')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->dropIndex(['wmo_header_key']);
            $table->dropColumn(['source', 'wmo_header_key']);
        });
    }
};
