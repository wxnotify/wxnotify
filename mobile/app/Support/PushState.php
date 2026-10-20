<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the app remembers about push on this phone: whether the subscriber turned it on, said
 * "not now" in the welcome flow, and when the token was last sent to the server. Signing out forgets
 * all of it. None of it is secret (the FCM token itself is never stored here).
 */
class PushState
{
    private const ENABLED = 'wxnotify.push.enabled';

    private const DECLINED = 'wxnotify.push.declined';

    private const REGISTERED_AT = 'wxnotify.push.registered_at';

    public function wantsPush(): bool
    {
        return (bool) Cache::get(self::ENABLED, false);
    }

    public function declined(): bool
    {
        return (bool) Cache::get(self::DECLINED, false);
    }

    /** Push on: also withdraws an earlier "not now". */
    public function markEnabled(): void
    {
        Cache::forever(self::ENABLED, true);
        Cache::forget(self::DECLINED);
    }

    /** Push off by choice: the welcome flow must not turn it on by itself. */
    public function markDisabled(): void
    {
        Cache::forget(self::ENABLED);
        Cache::forget(self::REGISTERED_AT);
        Cache::forever(self::DECLINED, true);
    }

    public function markDeclined(): void
    {
        Cache::forever(self::DECLINED, true);
    }

    public function markRegistered(): void
    {
        Cache::forever(self::REGISTERED_AT, now()->toIso8601String());
    }

    public function registeredWithin(int $hours): bool
    {
        $at = Cache::get(self::REGISTERED_AT);

        return $at !== null && now()->subHours($hours)->lessThan(Carbon::parse($at));
    }

    public function clear(): void
    {
        Cache::forget(self::ENABLED);
        Cache::forget(self::DECLINED);
        Cache::forget(self::REGISTERED_AT);
    }
}
