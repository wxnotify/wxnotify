<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The position a mobile location's address was last resolved for (or was
     * definitively found to have none). The address is looked up again only once the
     * location has moved a set distance from this point, and never while it is null.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->decimal('address_geocoded_lat', 10, 7)->nullable()->after('address');
            $table->decimal('address_geocoded_lng', 10, 7)->nullable()->after('address_geocoded_lat');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn(['address_geocoded_lat', 'address_geocoded_lng']);
        });
    }
};
