<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = 'other_forecasts_outlooks_discussions_and_reports';

    /** @var array<string, list<string>> new category => product codes */
    private const MOVES = [
        'convective_outlooks' => ['SPC.OUTLOOK', 'DY2', 'DY3', 'D48'],
        'other_discussions_reports_and_outlooks' => ['SPC.MD', 'STA', 'PWO'],
    ];

    /**
     * The SPC & WPC products shared one catch-all category with the Weather Alerts products;
     * the SPC & WPC alert form now groups them as Convective Outlooks and Other Discussions,
     * Reports, and Outlooks. A product moves only while it is still in the old category, so a
     * category staff chose in the admin catalog is left alone. Products the seeder has not
     * created yet (DY2 and friends on a fresh install) are skipped: the seeder assigns the new
     * categories itself.
     */
    public function up(): void
    {
        foreach (self::MOVES as $category => $codes) {
            DB::table('products')->whereIn('code', $codes)->where('category', self::OLD)->update(['category' => $category]);
        }
    }

    public function down(): void
    {
        foreach (self::MOVES as $category => $codes) {
            DB::table('products')->whereIn('code', $codes)->where('category', $category)->update(['category' => self::OLD]);
        }
    }
};
