<?php

namespace App\Support\Concerns;

use App\Services\PushBadge;
use App\Support\PushState;
use Illuminate\Support\Carbon;
use Native\Mobile\Attributes\Poll;

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
 * dispatches only while the app is open; the listener sets a cache flag (PushState) and the next tick
 * consumes it (one cache read per tick) and runs the full clear, on Android removing that push's tray
 * notification too. So such a push is counted and then cleared within about five seconds. If the event
 * never fires on a device, the behaviour is just the gap rule above.
 *
 * Known limitation: a push that lands while the app is in the background, followed by a return before the
 * loop stopped ticking (no gap), stays on the badge (and the Android tray) until the next tab open or gap.
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
        $foregroundPushReceived = app(PushState::class)->pullForegroundPush();

        if ($foregroundPushReceived || $this->wasInBackgroundSinceLastPushBadgeTick()) {
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
