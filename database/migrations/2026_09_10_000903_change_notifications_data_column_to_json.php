<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Filament's ->databaseNotifications() topbar bell queries
 * notifications.data via Postgres's JSON ->> operator
 * (`where('data->format', 'filament')`, resolved to `"data"->>'format'`),
 * which Postgres only supports on a json/jsonb-typed column. Laravel's stock
 * notifications migration types this column as plain text — confirmed via
 * a real failing query: "operator does not exist: text ->> unknown". Every
 * existing row is already valid JSON (json_encode()'d by the notification
 * channel), so this cast is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text');
    }
};
