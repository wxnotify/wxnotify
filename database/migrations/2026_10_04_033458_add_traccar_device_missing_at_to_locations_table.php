<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Set by the Traccar poller the first time it confirms (from the server's device
     * list) that a mobile location's linked device no longer exists, and cleared when
     * the device is found again or the location's device is changed. Demo and reset
     * Traccar servers re-issue device IDs, which otherwise leaves a location that is
     * never updated and says nothing.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->timestamp('traccar_device_missing_at')->nullable()->after('traccar_device_name');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('traccar_device_missing_at');
        });
    }
};
