<?php

namespace App\Console\Commands;

use App\Actions\Shapefiles\ScanShapefileLayer;
use App\Models\ShapefileLayer;
use Illuminate\Console\Command;

class ScanShapefileLayers extends Command
{
    protected $signature = 'shapefile:scan';

    protected $description = 'Poll every tracked AWIPS shapefile layer for a new NOAA version';

    public function handle(ScanShapefileLayer $scan): int
    {
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            if ($scan($layer) !== null) {
                $staged++;
                $this->info("New version staged for {$layer->name}");
            }
        }

        $this->info("Scan complete: {$staged} layer(s) staged a new version.");

        return self::SUCCESS;
    }
}
