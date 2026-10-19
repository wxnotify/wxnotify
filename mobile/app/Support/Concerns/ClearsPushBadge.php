<?php

namespace App\Support\Concerns;

use App\Services\PushBadge;
use App\Support\PushState;
use Illuminate\Support\Carbon;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Facades\System;

/**
 * Used by every bottom-nav tab screen (Alerts, Map, Products, Settings), so opening the app on any tab
 * clears the icon badge and resets the server's unread count. The using screen calls
 * clearPushBadgeOnOpen() from mount().
 *
 * Nothing tells PHP when the app comes back from the background, so a five-second poll stands in until
 * the app-lifecycle plugin's foreground event supports v4. An ordinary tick never calls the plugin (on
 * Android clearBadge() cancels every tray notification, and the loop can keep ticking for a while in the
 * background, which would wipe a push before it is seen); it only retries a failed server reset. A tick
 * more than three intervals after the previous one means the screen was not ticking, i.e. the app was
 * in the background, so it clears the badge and resets the server count.
 *
 * Foreground pushes: every push names App\Events\ForegroundPushReceived in its data, which the plugin
 * dispatches only while the app is open; the listener sets a cache flag (PushState). The flag is read
 * each tick and cleared when set. On iOS a set flag runs the full clear, so a push that was counted
 * while the app was open is cleared and reset within 0-5 seconds. On Android the flag is consumed and
 * nothing is cleared: Android has no badge number and the server never counts Android channels, so there
 * is nothing to reset, and cancelAll() would make the push's heads-up banner and tray entry vanish at
 * once; the OS keeps managing them. If the event never fires on a device, the behaviour is just the gap
 * rule above.
 *
 * Known limitations: a push that lands while the app is in the background, followed by a return before
 * the loop stopped ticking (no gap), stays on the badge (and the Android tray) until the next tab open
 * or gap. A flag set in the foreground and followed within seconds by a background push can cost one
 * extra iOS clear while the loop still ticks.
 */
trait ClearsPushBadge
{
    private const PUSH_BADGE_POLL_MS = 5000;

    /** Ticks missed before a gap counts as a stay in the background. */
    private const PUSH_BADGE_MISSED_TICKS = 3;

    /** When the badge was last cleared by this screen (mount or poll tick), to spot a return from the background. */
    public ?string $pushBadgeTickedAt = null;

    protected function clearPushBadgeOnOpen(): void
    {
        app(PushBadge::class)->clear();

        $this->pushBadgeTickedAt = now()->toRfc3339String(true);
    }

    #[Poll(self::PUSH_BADGE_POLL_MS)]
    public function pollPushBadge(): void
    {
        $clearForForegroundPush = app(PushState::class)->pullForegroundPush() && System::isIos();

        if ($clearForForegroundPush || $this->wasInBackgroundSinceLastPushBadgeTick()) {
            app(PushBadge::class)->clear();
        } else {
            app(PushBadge::class)->retryPendingReset();
        }

        $this->pushBadgeTickedAt = now()->toRfc3339String(true);
    }

    private function wasInBackgroundSinceLastPushBadgeTick(): bool
    {
        return $this->pushBadgeTickedAt === null
            || Carbon::parse($this->pushBadgeTickedAt)->diffInMilliseconds(now(), true) > self::PUSH_BADGE_MISSED_TICKS * self::PUSH_BADGE_POLL_MS;
    }
}
