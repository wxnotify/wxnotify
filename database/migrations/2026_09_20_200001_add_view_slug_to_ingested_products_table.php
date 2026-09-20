<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->string('view_slug')->nullable()->unique()->after('wmo_header_key');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table) {
            $table->dropUnique(['view_slug']);
            $table->dropColumn('view_slug');
        });
    }
};
