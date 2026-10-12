<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archive search matches a catalog product with a 3-character AWIPS category code (AFD, TAF, FLS ...) against
     * the first 3 characters of the AWIPS ID of rows the pipeline left unclassified (no product_id). The pipeline
     * classifies some rows of a category and not others (most FLS rows have a product_id, 109 of ~1,200 do not), which
     * the planner cannot see through two separate conditions: it walked the whole received_at index (400 ms on ~540k
     * rows). One expression carries both facts, so its statistics are accurate and a rare category uses this index.
     * The expression must match App\Actions\Products\SearchProducts::UNCLASSIFIED_CATEGORY exactly.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX ingested_products_unclassified_category_index ON ingested_products ((CASE WHEN product_id IS NULL THEN lower(left(awipsid, 3)) END))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ingested_products_unclassified_category_index');
    }
};
