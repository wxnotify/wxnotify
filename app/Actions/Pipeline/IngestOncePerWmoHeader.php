<?php

namespace App\Actions\Pipeline;

use App\Models\IngestedProduct;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class IngestOncePerWmoHeader
{
    /**
     * Runs $ingest unless a product with the same header key was already ingested (by either feed).
     * A short lock closes the race where both feeds deliver the same bulletin at once.
     * Returns true when $ingest ran, false when the product was a duplicate.
     */
    public function __invoke(?string $key, Closure $ingest): bool
    {
        if ($key === null) {
            $ingest();

            return true;
        }

        try {
            return Cache::lock("wmo-header:{$key}", 10)->block((int) config('services.pipeline.wmo_lock_wait_seconds', 5), function () use ($key, $ingest): bool {
                if (IngestedProduct::where('wmo_header_key', $key)->exists()) {
                    return false;
                }

                $ingest();

                return true;
            });
        } catch (LockTimeoutException) {
            abort(503, 'Another feed is ingesting the same product; retry.');
        }
    }
}
