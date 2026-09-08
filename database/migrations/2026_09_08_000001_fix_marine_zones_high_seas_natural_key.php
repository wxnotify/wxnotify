<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * High Seas Marine Zones turns out to have a genuinely different real DBF
     * schema than Coastal/Offshore (verified by downloading the current file
     * directly): no ID field at all -- what NOAA calls `id` there is lowercase
     * and empty for every real record. `zone_id` can never be a meaningful
     * natural key for this one table, so its unique constraint moves to `name`
     * (the only field that's actually distinct per real record for this layer).
     *
     * Looks the constraint up by column rather than by a hardcoded name:
     * ActivateShapefileVersion's atomic swap renames tables via
     * `ALTER TABLE ... RENAME TO`, which Postgres does not propagate to a
     * table's own constraint/index names -- after even one activation, a
     * table's real constraint names no longer necessarily match what its
     * *current* name would suggest (harmless for normal reads/writes, since
     * constraint names don't affect enforcement, but it means a migration
     * can't assume the naming convention holds for tables this feature swaps).
     */
    public function up(): void
    {
        foreach (['marine_zones_high_seas', 'marine_zones_high_seas_staging'] as $table) {
            $constraint = $this->uniqueConstraintOn($table, 'zone_id');

            Schema::table($table, function (Blueprint $blueprint) use ($constraint, $table): void {
                if ($constraint !== null) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
                }

                $blueprint->unique('name');
            });
        }
    }

    public function down(): void
    {
        foreach (['marine_zones_high_seas', 'marine_zones_high_seas_staging'] as $table) {
            $constraint = $this->uniqueConstraintOn($table, 'name');

            Schema::table($table, function (Blueprint $blueprint) use ($constraint, $table): void {
                if ($constraint !== null) {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$constraint}");
                }

                $blueprint->unique('zone_id');
            });
        }
    }

    private function uniqueConstraintOn(string $table, string $column): ?string
    {
        $row = DB::selectOne(
            "SELECT con.conname
             FROM pg_constraint con
             JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = ANY(con.conkey)
             WHERE con.conrelid = ?::regclass
               AND con.contype = 'u'
               AND con.conkey = ARRAY[att.attnum]
               AND att.attname = ?",
            [$table, $column],
        );

        return $row?->conname;
    }
};
