<?php

namespace App\Jobs;

use App\Actions\Pipeline\BackfillOutlookRiskAreas;
use App\Actions\Pipeline\BuildBulletinSegments;
use App\Actions\Pipeline\SyncActiveWwa;
use App\Actions\Weather\MatchWeatherAlertRules;
use App\Models\IngestedProduct;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Dispatched once per IngestedProduct created, by App\Observers\IngestedProductObserver.
 * This is what actually reacts to a new product — matching subscriber rules and
 * (for VTEC products) updating active_wwas — so it runs on the queue rather than
 * inline in the ingest webhook's HTTP request: a slow or failing match must never
 * risk the pipeline client's retry contract for the ingest endpoint itself (see
 * .ai/rules/emwin-http.md), and this is what actually lets the alert engine's
 * processing run independently of whatever's serving that request.
 *
 * Takes an id rather than a model instance, matching App\Jobs\DeliverWeatherAlert's
 * reasoning: a product deleted between dispatch and processing is a no-op, not a
 * failed job.
 *
 * Branching originally mirrored IngestedProductObserver::created() exactly (it
 * replaced that method's body verbatim, which now just dispatches this job) and
 * has since grown its own cases — see .ai/rules/pipeline.md for why the replay
 * check still comes first: a replay copy must always go to MatchWeatherAlertRules
 * and never to SyncActiveWwa (replays never write active_wwas). The ptsdy1 check
 * comes next, before the general vtec_office branch, since PTSDY1 is neither a
 * replay nor VTEC-tagged but still needs to skip both of those paths — see
 * App\Actions\Pipeline\BackfillOutlookRiskAreas.
 *
 * Before any of that branching, a VTEC bulletin's segments (one per section x VTEC line) are
 * built, for replays too: matching reads them instead of the bulletin's first zone block and first
 * VTEC line, so they must exist first. It is built here and not in IngestProductText because the
 * observer queues this job from the model's created event, before the ingest action returns.
 */
class ProcessIngestedProduct implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $ingestedProductId,
    ) {}

    public function handle(): void
    {
        $product = IngestedProduct::find($this->ingestedProductId);

        if ($product === null) {
            logger()->warning('ProcessIngestedProduct: product no longer exists', [
                'ingested_product_id' => $this->ingestedProductId,
            ]);

            return;
        }

        $this->buildSegments($product);

        if ($product->replay_of_id !== null) {
            // A replay only exercises alert delivery, so it must not write active_wwas.
            app(MatchWeatherAlertRules::class)($product);

            return;
        }

        if ($product->awipsid === 'ptsdy1') {
            // The SPC Day 1 Outlook Areal Outline: merges into its paired
            // SWODY1 event rather than independently matching -- see
            // BackfillOutlookRiskAreas's own docblock for why.
            app(BackfillOutlookRiskAreas::class)($product);

            return;
        }

        if ($product->vtec_office !== null) {
            app(SyncActiveWwa::class)($product);

            return;
        }

        app(MatchWeatherAlertRules::class)($product);
    }

    /**
     * A failure is logged, never thrown: a bulletin whose sections cannot be split is still
     * processed, the old way, with no segments (BuildBulletinSegments parses before it deletes, so
     * a failure leaves the previous segments, if any, in place).
     */
    private function buildSegments(IngestedProduct $product): void
    {
        if ($product->vtec_office === null) {
            return;
        }

        try {
            app(BuildBulletinSegments::class)($product);
        } catch (Throwable $e) {
            logger()->error('ProcessIngestedProduct: failed to build the segments of a bulletin', [
                'ingested_product_id' => $product->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
