<?php

namespace App\Support\Setup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * When the queue heartbeat job last ran. The scheduler dispatches RecordQueueHeartbeat every
 * minute and a queue worker runs it, so a recent timestamp proves both are working.
 */
final class QueueHeartbeat
{
    private const CACHE_KEY = 'setup.queue-heartbeat';

    public static function record(): void
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());
    }

    public static function lastRanAt(): ?Carbon
    {
        $value = Cache::get(self::CACHE_KEY);

        return $value === null ? null : Carbon::parse($value);
    }
}
