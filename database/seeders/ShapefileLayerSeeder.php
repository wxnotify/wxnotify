<?php

namespace Database\Seeders;

use App\Models\ShapefileLayer;
use App\Support\Shapefiles\ShapefileLayerDefinitions;
use Illuminate\Database\Seeder;

class ShapefileLayerSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ShapefileLayerDefinitions::all() as $definition) {
            ShapefileLayer::updateOrCreate(
                ['key' => $definition->key],
                ['name' => $definition->name, 'source_url' => $definition->sourceUrl],
            );
        }
    }
}
