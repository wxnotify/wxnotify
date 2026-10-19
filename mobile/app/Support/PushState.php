<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * What the app remembers about push on this phone: whether the subscriber turned it on, said
 * "not now" to the prompt, and when the token was last sent to the server. Signing out forgets
 * all of it, including a pending foreground-push flag. None of it is secret (the FCM token itself is never stored here).
 */
class PushState
{
    private const ENABLED = 'wxnotify.push.enabled';

    private const DECLINED = 'wxnotify.push.declined';

    private const REGISTERED_AT = 'wxnotify.push.registered_at';

    private const FOREGROUND_PUSH = 'wxnotify.push.foreground_push';

    private const RESET_PENDING = 'wxnotify.push.badge_reset_pending';

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

    /** Push off by choice: the prompt must not come back by itself. */
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

    /** The server's unread count still needs resetting because the last attempt failed. */
    public function resetPending(): bool
    {
        return (bool) Cache::get(self::RESET_PENDING, false);
    }

    public function markResetPending(): void
    {
        Cache::forever(self::RESET_PENDING, true);
    }

    public function clearResetPending(): void
    {
        Cache::forget(self::RESET_PENDING);
    }

    /** A push arrived while the app was open (set from the plugin's ephemeral PHP runtime, through the shared cache). */
    public function markForegroundPush(): void
    {
        Cache::forever(self::FOREGROUND_PUSH, true);
    }

    /** Whether a foreground push was flagged since the last call; reading it clears it in one step. */
    public function pullForegroundPush(): bool
    {
        return (bool) Cache::pull(self::FOREGROUND_PUSH, false);
    }

    public function clear(): void
    {
        Cache::forget(self::FOREGROUND_PUSH);
        Cache::forget(self::RESET_PENDING);
        Cache::forget(self::ENABLED);
        Cache::forget(self::DECLINED);
        Cache::forget(self::REGISTERED_AT);
    }
}
