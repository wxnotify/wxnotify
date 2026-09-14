<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Blueprint's ->change() needs doctrine/dbal, which this project
        // doesn't install -- raw SQL avoids that dependency entirely.
        DB::statement('ALTER TABLE locations ALTER COLUMN lat DROP NOT NULL');
        DB::statement('ALTER TABLE locations ALTER COLUMN lng DROP NOT NULL');

        Schema::table('locations', function (Blueprint $table): void {
            $table->string('mobile_source')->nullable()->after('type');
            $table->timestamp('mobile_position_updated_at')->nullable()->after('timezone');
            $table->unsignedTinyInteger('mobile_update_interval_minutes')->nullable()->after('mobile_position_updated_at');
            $table->foreignId('traccar_server_id')->nullable()->after('mobile_update_interval_minutes')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('traccar_device_id')->nullable()->after('traccar_server_id');
            $table->string('traccar_device_name')->nullable()->after('traccar_device_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('traccar_server_id');
            $table->dropColumn(['mobile_source', 'mobile_position_updated_at', 'mobile_update_interval_minutes', 'traccar_device_id', 'traccar_device_name']);
        });

        DB::statement('ALTER TABLE locations ALTER COLUMN lat SET NOT NULL');
        DB::statement('ALTER TABLE locations ALTER COLUMN lng SET NOT NULL');
    }
};
