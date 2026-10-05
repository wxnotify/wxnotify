<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Whether the subscriber wants this phone to share its location. The native watch is the source
 * of truth for whether it is running; this flag is what the app is supposed to be doing. Signing
 * out forgets it.
 */
class LocationSharingState
{
    private const WANTED = 'wxnotify.location.wanted';

    public function wanted(): bool
    {
        return (bool) Cache::get(self::WANTED, false);
    }

    public function markWanted(): void
    {
        Cache::forever(self::WANTED, true);
    }

    public function markOff(): void
    {
        Cache::forget(self::WANTED);
    }

    public function clear(): void
    {
        $this->markOff();
    }
}
