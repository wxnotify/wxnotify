<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'SPC.FIRE';

    private const OLD_NAME = 'SPC Fire Weather Outlook';

    private const NEW_NAME = 'SPC Day 1 Fire Weather Outlook';

    /**
     * SPC.FIRE covers the Day 1 fire weather outlook but its name did not say which day,
     * unlike "SPC Day 2 / Day 3-8 Fire Weather Outlook Discussion". Only a name still at
     * the shipped value is renamed, so a name staff edited in the catalog is left alone.
     * The code is unchanged, so every alert rule and matching rule keeps working.
     */
    public function up(): void
    {
        DB::table('products')->where('code', self::CODE)->where('name', self::OLD_NAME)->update(['name' => self::NEW_NAME]);
    }

    public function down(): void
    {
        DB::table('products')->where('code', self::CODE)->where('name', self::NEW_NAME)->update(['name' => self::OLD_NAME]);
    }
};
