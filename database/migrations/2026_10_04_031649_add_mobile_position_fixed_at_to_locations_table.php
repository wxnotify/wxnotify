<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When Traccar says the device's latest position was actually located (its
     * `fixTime`). Distinct from mobile_position_updated_at, which is when WxNotify
     * last polled successfully: that one drives the poll schedule and the map's
     * stale pin, and a parked device's fixTime can be days old.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->timestamp('mobile_position_fixed_at')->nullable()->after('mobile_position_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('mobile_position_fixed_at');
        });
    }
};
