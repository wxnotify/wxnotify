<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use Wxnotify\BadgeCount\Facades\BadgeCount;

/**
 * Sets the iOS app icon badge to the number of unread alerts through the local badge-count plugin
 * (Android has no badge number; the plugin answers success there). Never throws: the badge is a
 * courtesy, and the app corrects it from the feed's unread count on the next load. Off a device the
 * plugin does nothing.
 */
class AppBadge
{
    public function set(int $count): void
    {
        try {
            BadgeCount::set(max(0, $count));
        } catch (Throwable $e) {
            Log::info('App badge set failed: '.$e::class);
        }
    }

    public function clear(): void
    {
        $this->set(0);
    }
}
