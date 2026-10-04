<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODE = 'SPC.OUTLOOK';

    private const OLD_NAME = 'SPC Convective Outlook';

    private const NEW_NAME = 'SPC Day 1 Convective Outlook';

    /**
     * SPC.OUTLOOK covers the Day 1 outlook (SWODY1 and its PTSDY1 areal outline) but was
     * the only convective outlook whose name did not say which day, unlike
     * "SPC Day 2 / Day 3 / Day 4-8 Convective Outlook". Only a name still at the shipped
     * value is renamed, so a name staff edited in the catalog is left alone. The code is
     * unchanged, so every alert rule and matching rule keeps working.
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
