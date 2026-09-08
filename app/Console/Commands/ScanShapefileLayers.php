<?php

namespace App\Console\Commands;

use App\Actions\Shapefiles\ImportShapefileVersion;
use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Models\ShapefileLayer;
use Illuminate\Console\Command;
use Throwable;

class ScanShapefileLayers extends Command
{
    protected $signature = 'shapefile:scan';

    protected $description = 'Poll every tracked AWIPS shapefile layer for a new NOAA version and import it into staging';

    public function handle(ScanShapefileLayer $scan, ImportShapefileVersion $import): int
    {
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            $version = $scan($layer);

            if ($version === null) {
                continue;
            }

            $staged++;

            try {
                $import($version);
                $this->info("New version staged and imported for {$layer->name}");
            } catch (Throwable $e) {
                $this->error("New version staged for {$layer->name}, but import failed: {$e->getMessage()}");
            }
        }

        $this->info("Scan complete: {$staged} layer(s) staged a new version.");

        return self::SUCCESS;
    }
}
