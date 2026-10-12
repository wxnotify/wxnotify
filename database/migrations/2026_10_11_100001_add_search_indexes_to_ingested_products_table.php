<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The archive search (App\Actions\Products\SearchProducts) matches lower-cased prefixes and a lower-cased issuing
     * centre, which the plain btree indexes cannot serve, and the type filter had no index on product_id at all. On
     * ~540k rows a rare combination otherwise walks the received_at index and filters every row it passes.
     */
    public function up(): void
    {
        DB::statement('CREATE INDEX ingested_products_lower_awipsid_prefix_index ON ingested_products (lower(awipsid) text_pattern_ops)');
        DB::statement("CREATE INDEX ingested_products_lower_wmo_header_prefix_index ON ingested_products (lower(ttaaii || ' ' || cccc) text_pattern_ops)");
        DB::statement('CREATE INDEX ingested_products_lower_cccc_index ON ingested_products (lower(cccc))');
        DB::statement('CREATE INDEX ingested_products_product_id_received_at_index ON ingested_products (product_id, received_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ingested_products_lower_awipsid_prefix_index');
        DB::statement('DROP INDEX IF EXISTS ingested_products_lower_wmo_header_prefix_index');
        DB::statement('DROP INDEX IF EXISTS ingested_products_lower_cccc_index');
        DB::statement('DROP INDEX IF EXISTS ingested_products_product_id_received_at_index');
    }
};
