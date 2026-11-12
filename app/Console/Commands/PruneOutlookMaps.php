<?php

namespace App\Console\Commands;

use App\Models\OutlookReportRun;
use App\Support\OutlookMapDisk;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class PruneOutlookMaps extends Command
{
    protected $signature = 'outlook:prune-maps';

    protected $description = 'Delete stored Outlook report map copies older than the retention window';

    public function handle(): int
    {
        $pruned = 0;
        $disk = OutlookMapDisk::disk();

        OutlookReportRun::query()
            ->whereNotNull('maps')
            ->where('created_at', '<=', now()->subDays(OutlookReportRun::MAP_DAYS))
            ->chunkById(100, function ($runs) use (&$pruned, $disk): void {
                foreach ($runs as $run) {
                    if (blank($run->batch_key)) {
                        continue;
                    }

                    try {
                        if (! $disk->deleteDirectory('outlook-maps/'.$run->batch_key)) {
                            Log::warning('Outlook map prune could not delete a run directory.');

                            continue;
                        }

                        $run->forceFill(['maps' => null])->save();
                        $pruned++;
                    } catch (Throwable $e) {
                        Log::warning('Outlook map prune failed for a run.', ['exception' => $e::class]);
                    }
                }
            });

        $this->info("Pruned map copies for {$pruned} outlook report runs.");

        $swept = $this->sweepOrphanedDirectories();

        $this->info("Swept {$swept} orphaned map folders.");

        return self::SUCCESS;
    }

    /**
     * Delete map folders no run row points at any more (the user, and so the row, was deleted)
     * once their newest file is as old as the retention window. A younger folder may belong to a
     * run whose row is not written yet; a folder with a row is left to the row pass.
     */
    private function sweepOrphanedDirectories(): int
    {
        $swept = 0;
        $disk = OutlookMapDisk::disk();

        try {
            $directories = $disk->directories('outlook-maps');
        } catch (Throwable $e) {
            Log::warning('Outlook map sweep could not list the map folders.', ['exception' => $e::class]);

            return 0;
        }

        $cutoff = now()->subDays(OutlookReportRun::MAP_DAYS)->getTimestamp();

        foreach ($directories as $directory) {
            try {
                $name = basename($directory);

                if (blank($name) || OutlookReportRun::query()->where('batch_key', $name)->exists()) {
                    continue;
                }

                $newest = collect($disk->allFiles($directory))
                    ->map(fn (string $file): int => $disk->lastModified($file))
                    ->max();

                if ($newest !== null && $newest > $cutoff) {
                    continue;
                }

                if (! $disk->deleteDirectory($directory)) {
                    Log::warning('Outlook map sweep could not delete an orphaned folder.');

                    continue;
                }

                $swept++;
            } catch (Throwable $e) {
                Log::warning('Outlook map sweep failed for a folder.', ['exception' => $e::class]);
            }
        }

        return $swept;
    }
}
