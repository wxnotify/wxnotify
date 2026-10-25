<?php

namespace App\Jobs;

use App\Actions\Pipeline\BackfillOutlookRiskAreas;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * The one delayed re-check for an SPC Day 1 areal outline (PTSDY1) that found no outlook text (SWODY1)
 * when it arrived: App\Actions\Pipeline\BackfillOutlookRiskAreas dispatches it
 * `wxnotify.outlook_pairing_delay_seconds` later, and its pairAfterWaiting() merges the risk areas into
 * the SWODY1 of the same issuance if that has arrived since. If none has, the outline is matched on its own,
 * the documented "never lose the alert" fallback.
 *
 * Unique per outline id, so a retried or duplicated ProcessIngestedProduct schedules it once; the lock is
 * taken at dispatch and released when the job finishes, bounded by uniqueFor() (the delay plus an hour) so
 * a lost worker cannot hold it. It is dispatched outside any DB transaction (see .ai/rules/pipeline.md on
 * unique jobs and the database cache store). Running it twice is harmless: the second run finds the outlook
 * already has its risk areas, and a second fallback is stopped by DeliverWeatherAlert's own guards.
 *
 * The worker runs with --tries=1, so a throw would drop the fallback alert: nothing is ever thrown. A
 * failed pairing still falls back to matching the outline on its own (a possible second copy of the
 * outlook beats a lost alert), and every failure is logged with the outline's id and the exception class
 * only. Takes an id, like ProcessIngestedProduct: an outline deleted meanwhile is a no-op.
 */
class PairOutlookAreasAfterDelay implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ingestedProductId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->ingestedProductId;
    }

    /**
     * Seconds the unique lock lives at most, should the job never run, finish or fail.
     */
    public function uniqueFor(): int
    {
        return BackfillOutlookRiskAreas::pairingDelaySeconds() + 3600;
    }

    public function handle(): void
    {
        $ptsdy1 = IngestedProduct::find($this->ingestedProductId);

        if ($ptsdy1 === null) {
            logger()->warning('PairOutlookAreasAfterDelay: areal outline no longer exists', [
                'ingested_product_id' => $this->ingestedProductId,
            ]);

            return;
        }

        try {
            if (app(BackfillOutlookRiskAreas::class)->pairAfterWaiting($ptsdy1)) {
                return;
            }
        } catch (Throwable $e) {
            logger()->error('PairOutlookAreasAfterDelay: pairing failed, matching the areal outline on its own', [
                'ingested_product_id' => $ptsdy1->id,
                'exception' => $e::class,
            ]);
        }

        try {
            app(MatchWeatherAlertRules::class)($ptsdy1);
        } catch (Throwable $e) {
            logger()->error('PairOutlookAreasAfterDelay: matching the areal outline on its own failed', [
                'ingested_product_id' => $ptsdy1->id,
                'exception' => $e::class,
            ]);
        }
    }
}
