<?php

namespace App\Observers;

use App\Jobs\ProcessIngestedProduct;
use App\Models\IngestedProduct;

class IngestedProductObserver
{
    /**
     * Matching/SyncActiveWwa used to run here inline; that logic now lives in
     * App\Jobs\ProcessIngestedProduct, queued so it never runs inside the ingest
     * webhook's own HTTP request (see that job's docblock, and .ai/rules/pipeline.md
     * for the branching itself).
     */
    public function created(IngestedProduct $product): void
    {
        ProcessIngestedProduct::dispatch($product->id);
    }
}
