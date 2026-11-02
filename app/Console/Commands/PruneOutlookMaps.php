<?php

namespace App\Console\Commands;

use App\Models\OutlookReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PruneOutlookMaps extends Command
{
    protected $signature = 'outlook:prune-maps';

    protected $description = 'Delete stored Outlook report map copies older than the retention window';

    public function handle(): int
    {
        $pruned = 0;

        OutlookReportRun::query()
            ->whereNotNull('maps')
            ->where('created_at', '<=', now()->subDays(OutlookReportRun::MAP_DAYS))
            ->chunkById(100, function ($runs) use (&$pruned): void {
                foreach ($runs as $run) {
                    try {
                        Storage::disk('local')->deleteDirectory('outlook-maps/'.$run->batch_key);
                        $run->forceFill(['maps' => null])->save();
                        $pruned++;
                    } catch (Throwable $e) {
                        Log::warning('Outlook map prune failed for a run.', ['exception' => $e::class]);
                    }
                }
            });

        $this->info("Pruned map copies for {$pruned} outlook report runs.");

        return self::SUCCESS;
    }
}
