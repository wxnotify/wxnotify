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
        // -v (every Artisan command's built-in verbosity flag, not a custom
        // option) is what turns this on -- no reason to invent a separate
        // --verbose flag for something Symfony Console already gives every
        // command for free.
        $verbose = $this->getOutput()->isVerbose();

        foreach (ShapefileLayer::all() as $layer) {
            if ($verbose) {
                $this->line("Scanning {$layer->name}...");
            }

            $version = $scan($layer, $verbose ? fn (string $message) => $this->line("  {$message}") : null);

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
