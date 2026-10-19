<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Whether the welcome flow has already been shown on this install. It lives in its own cache key
 * that signing out never touches (the flow is once per install, not once per sign-in) and that
 * goes away with the app's data on uninstall. It is deliberately not in secure storage: the iOS
 * Keychain survives an uninstall.
 */
class WelcomeState
{
    private const SEEN = 'wxnotify.welcome.seen';

    public function seen(): bool
    {
        return (bool) Cache::get(self::SEEN, false);
    }

    public function markSeen(): void
    {
        Cache::forever(self::SEEN, true);
    }
}
