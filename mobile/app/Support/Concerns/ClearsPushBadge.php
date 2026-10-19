<?php

namespace App\Support\Concerns;

use App\Services\PushBadge;
use Illuminate\Support\Carbon;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

/**
 * Used by every bottom-nav tab screen (Alerts, Map, Products, Settings), so opening the app on any tab
 * clears the icon badge and resets the server's unread count. The using screen calls
 * clearPushBadgeOnOpen() from mount(); a push received in the foreground clears too.
 *
 * Nothing tells PHP when the app comes back from the background, so a five-second poll stands in until
 * the app-lifecycle plugin's foreground event supports v4. An ordinary tick never calls the plugin (on
 * Android clearBadge() cancels every tray notification, and the loop can keep ticking for a while in the
 * background, which would wipe a push before it is seen); it only retries a failed server reset. A tick
 * more than three intervals after the previous one means the screen was not ticking, i.e. the app was
 * in the background, so it clears the badge and resets the server count.
 *
 * Known limitation: a push that lands while the app is in the background, followed by a return before
 * the loop stopped ticking (no gap), stays on the badge until the next gap or foreground push.
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

    /**
     * A push that arrives while the app is open has been seen: clear it and its server count.
     */
    #[On(PushNotificationReceived::class)]
    public function clearPushBadgeOnForegroundPush(): void
    {
        app(PushBadge::class)->clear();
    }

    #[Poll(self::PUSH_BADGE_POLL_MS)]
    public function pollPushBadge(): void
    {
        if ($this->wasInBackgroundSinceLastPushBadgeTick()) {
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
