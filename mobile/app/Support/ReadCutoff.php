<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The cutoff of a "Mark all as read": the feed's `meta.as_of` (ISO-8601 UTC with microseconds) the phone
 * last loaded. Only alerts sent before the cutoff's second count as read, the rule the server applies to its
 * second-precision `created_at`, so an alert that arrived after the list loaded (or in that same second) stays unread. A null cutoff (an older backend, or a pending mark all from an earlier build) reads
 * everything, as before.
 */
class ReadCutoff
{
    /**
     * True when an alert sent at `$dispatchedAt` is read by a mark all with this cutoff: sent strictly before the
     * cutoff's whole second. An alert whose time
     * cannot be read is left unread (the safe way to be wrong for a weather warning), as is everything when the
     * cutoff itself cannot be read.
     */
    public static function covers(?string $cutoff, mixed $dispatchedAt): bool
    {
        if ($cutoff === null) {
            return true;
        }

        if (! is_string($dispatchedAt)) {
            return false;
        }

        try {
            return CarbonImmutable::parse($dispatchedAt)->lessThan(CarbonImmutable::parse($cutoff)->startOfSecond());
        } catch (Throwable) {
            return false;
        }
    }
}
