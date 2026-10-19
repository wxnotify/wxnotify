<?php

namespace App\Actions\Setup;

use App\Support\Setup\ReferenceDataStatus;
use Database\Seeders\DidYouKnowSeeder;
use Database\Seeders\ProductCatalogSeeder;
use Database\Seeders\ShapefileLayerSeeder;

/**
 * Installs the reference data a fresh install is missing, and only that: the seeders rewrite
 * existing rows, so each one runs only for a set that is not installed yet. Nothing is deleted.
 * Channel types come from a migration and are never touched here.
 */
final class InstallReferenceData
{
    /**
     * @return list<string> The keys of the sets this run installed.
     */
    public function __invoke(): array
    {
        $installed = collect(ReferenceDataStatus::sets())->pluck('installed', 'key');

        $seeders = [
            'product_catalog' => ProductCatalogSeeder::class,
            'tips' => DidYouKnowSeeder::class,
            'shapefile_layers' => ShapefileLayerSeeder::class,
        ];

        $ran = [];

        foreach ($seeders as $key => $seeder) {
            if ($installed[$key]) {
                continue;
            }

            app($seeder)->run();
            $ran[] = $key;
        }

        return $ran;
    }
}
