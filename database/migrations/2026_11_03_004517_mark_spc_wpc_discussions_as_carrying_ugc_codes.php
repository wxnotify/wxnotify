<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const CODES = ['SPC.MD', 'WPC.MPD'];

    /**
     * The SPC Mesoscale Discussion and the WPC Mesoscale Precipitation Discussion carry a UGC line of
     * state-level codes ("OKZ000-TXZ000-"), which the pipeline stores in `ugc_codes` (stored rows
     * show it), but the catalog shipped them with `has_ugc` false. The flag decides which subject
     * placeholders a product's subject box offers ({states} needs UGC codes), so these two products
     * never listed {states} though it works. Both also have a polygon, so the office-wide matching
     * of `matchesLocationOffice()` (which needs no UGC and no polygon) is unaffected. Only a flag
     * still at the shipped value is changed, so one staff set by hand is left alone.
     */
    public function up(): void
    {
        DB::table('products')->whereIn('code', self::CODES)->where('has_ugc', false)->update(['has_ugc' => true]);
    }

    public function down(): void
    {
        DB::table('products')->whereIn('code', self::CODES)->where('has_ugc', true)->update(['has_ugc' => false]);
    }
};
