<?php

namespace App\Actions\Admin\FactoryReset;

use Database\Seeders\ChannelTypeSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class ResetCatalogs
{
    /**
     * Restores the shipped catalogs IN PLACE: shipped products keep their ids
     * (so subscribers' alert_rule_product rows survive), admin-added products
     * and channel types are deleted.
     *
     * @return array{custom_products_removed: int, custom_channel_types_removed: int}
     */
    public function __invoke(): array
    {
        /** @var list<array<string, mixed>> $shipped */
        $shipped = require database_path('data/shipped_product_catalog.php');
        $shippedCodes = array_column($shipped, 'code');

        $removedProducts = DB::table('products')->whereNotIn('code', $shippedCodes)->delete();

        // Pass 1: make sure every shipped row exists with its base columns so the
        // seeder can merge descriptions/examples onto them.
        foreach ($shipped as $row) {
            $this->restore($row);
        }

        (new ProductCatalogSeeder)->run();

        // Pass 2: the seeder reassigns sort_order on re-run; re-apply the snapshot.
        foreach ($shipped as $row) {
            $this->restore($row);
        }

        $shippedSlugs = array_column(ChannelTypeSeeder::SHIPPED, 'slug');
        $removedChannelTypes = DB::table('channel_types')->whereNotIn('slug', $shippedSlugs)->delete();
        (new ChannelTypeSeeder)->run();

        return [
            'custom_products_removed' => $removedProducts,
            'custom_channel_types_removed' => $removedChannelTypes,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function restore(array $row): void
    {
        $query = DB::table('products')->where('code', $row['code']);

        if ($query->exists()) {
            $query->update(Arr::except($row, ['code']) + ['updated_at' => now()]);

            return;
        }

        DB::table('products')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
    }
}
