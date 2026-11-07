<?php

namespace App\Filament\Dashboard\Pages\LiveWeather\Concerns;

use Illuminate\Support\Facades\RateLimiter;

/**
 * A per-viewer budget for the Live Weather pages' data calls.
 */
trait LimitsDataCalls
{
    /**
     * Counts one call against the signed-in viewer's minute under the given key prefix and says
     * whether it is over the limit (keyed on the user, never the IP). A limited call must do no
     * work.
     */
    protected function callLimited(string $keyPrefix, int $limit): bool
    {
        $key = $keyPrefix.':'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }
}
