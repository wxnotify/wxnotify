<?php

namespace App\Actions\Shapefiles;

use App\Models\ShapefileLayer;
use Closure;
use Throwable;

/**
 * Scans every tracked layer for a new NOAA version and imports each one it stages into staging,
 * carrying on when one import fails. A layer with no new version whose pending version never
 * finished importing (a failed or killed import) has that import run again, since the scan alone
 * would never see it as new. Everything worth saying goes through $onLine(message, level):
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
     * @return int The number of layers that staged a new version or finished an unfinished import.
     */
    public function __invoke(?Closure $onLine = null): int
    {
        $report = fn (string $message, string $level) => $onLine?->__invoke($message, $level);
        $staged = 0;

        foreach (ShapefileLayer::all() as $layer) {
            $report("Scanning {$layer->name}...", 'line');

            $version = ($this->scan)($layer, fn (string $message) => $report("  {$message}", 'line'));

            if ($version === null) {
                $staged += $this->retryUnfinishedImport($layer, $report);

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

    /**
     * @param  Closure(string, string): void  $report
     * @return int 1 when an unfinished import was completed, else 0.
     */
    private function retryUnfinishedImport(ShapefileLayer $layer, Closure $report): int
    {
        $pending = $layer->pendingVersion()->first();

        if ($pending === null || $pending->isImported()) {
            return 0;
        }

        $report("  The import of {$pending->filename} did not finish — importing it again", 'line');

        try {
            ($this->import)($pending);
            $report("Unfinished import completed for {$layer->name}", 'info');

            return 1;
        } catch (Throwable $e) {
            $report("The unfinished import for {$layer->name} failed again: {$e->getMessage()}", 'error');

            return 0;
        }
    }
}
