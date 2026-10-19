<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use Closure;
use Throwable;

/**
 * Scans every tracked layer for a new NOAA version and imports each one it stages into staging,
 * carrying on when one import fails. Everything worth saying goes through $onLine(message, level):
 * level `info` and `error` are results, `line` is the plain narration the command shows with -v.
 */
class ScanAllShapefileLayers
{
    public function __construct(
        private readonly ScanShapefileLayer $scan,
        private readonly ImportShapefileVersion $import,
    ) {}

    /**
     * @param  (Closure(string, string): void)|null  $onLine
     * @return int The number of layers that staged a new version.
     */
    public function __invoke(?Closure $onLine = null): int
    {
        $report = fn (string $message, string $level) => $onLine?->__invoke($message, $level);
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            $report("Scanning {$layer->name}...", 'line');

            $version = ($this->scan)($layer, fn (string $message) => $report("  {$message}", 'line'));

            if ($version === null) {
                continue;
            }

            $staged++;

            try {
                ($this->import)($version);
                $report("New version staged and imported for {$layer->name}", 'info');
            } catch (Throwable $e) {
                $report("New version staged for {$layer->name}, but import failed: {$e->getMessage()}", 'error');
            }
        }

        return $staged;
    }
}
