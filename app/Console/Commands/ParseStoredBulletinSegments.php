<?php

namespace App\Console\Commands;

use App\Actions\Pipeline\BuildBulletinSegments;
use App\Models\IngestedProduct;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds the segments of the VTEC bulletins stored in the last N days. Run once after the
 * section-aware pipeline is deployed so the currently active events have segments (the active
 * event coverage rebuild reads them). By default it fills only bulletins that have no segments
 * yet, so running it again is safe. With --force it also rebuilds bulletins that already have
 * segments, which replaces those segments and drops the delivery links recorded on them. It
 * parses and stores only: it sends nothing, dispatches no job and writes no delivery log.
 */
class ParseStoredBulletinSegments extends Command
{
    protected $signature = 'bulletins:parse-segments {--days=2 : How many days of stored bulletins to build} {--force : Rebuild bulletins that already have segments too (this drops the delivery links of the segments it replaces)}';

    protected $description = 'Rebuild the sections (segments) of the stored VTEC bulletins; sends nothing';

    public function handle(BuildBulletinSegments $build): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be at least 1.');

            return self::FAILURE;
        }

        $rebuilt = 0;
        $failed = 0;

        IngestedProduct::query()
            ->whereNotNull('vtec_office')
            ->where('received_at', '>=', now()->subDays($days))
            ->when(! $this->option('force'), fn ($query) => $query->whereDoesntHave('segments'))
            ->chunkById(200, function ($bulletins) use ($build, &$rebuilt, &$failed): void {
                foreach ($bulletins as $bulletin) {
                    try {
                        $build($bulletin);
                        $rebuilt++;
                    } catch (Throwable $e) {
                        $failed++;
                        logger()->warning('bulletins:parse-segments: failed to rebuild a bulletin', [
                            'ingested_product_id' => $bulletin->id,
                            'exception' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->info("Rebuilt the segments of {$rebuilt} ".Str::plural('bulletin', $rebuilt)." ({$failed} failed).");

        return self::SUCCESS;
    }
}
