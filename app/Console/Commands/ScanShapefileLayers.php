<?php

namespace App\Console\Commands;

use App\Actions\Shapefiles\ScanAllShapefileLayers;
use Illuminate\Console\Command;

class ScanShapefileLayers extends Command
{
    protected $signature = 'shapefile:scan';

    protected $description = 'Poll every tracked AWIPS shapefile layer for a new NOAA version and import it into staging';

    public function handle(ScanAllShapefileLayers $scanAll): int
    {
        // -v (every Artisan command's built-in verbosity flag, not a custom
        // option) is what turns the narration on -- no reason to invent a
        // separate --verbose flag for something Symfony Console already gives
        // every command for free.
        $verbose = $this->getOutput()->isVerbose();

        $staged = $scanAll(function (string $message, string $level) use ($verbose): void {
            match ($level) {
                'error' => $this->error($message),
                'info' => $this->info($message),
                default => $verbose ? $this->line($message) : null,
            };
        });

        $this->info("Scan complete: {$staged} layer(s) staged a new version.");

        return self::SUCCESS;
    }
}
