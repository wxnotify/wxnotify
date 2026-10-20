<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\LocalCache;
use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\PushNotifications;
use Throwable;

/**
 * Signs the subscriber out of this phone: best-effort revoke on the server (a failed call
 * must not leave them signed in), then forget the token and wipe everything cached.
 */
class SignOut
{
    public function __construct(
        private TokenStore $tokens,
        private WxNotifyApi $api,
        private LocalCache $cache,
        private PushState $push,
        private LocationSharing $location,
        private AppBadge $badge,
    ) {}

    public function __invoke(): void
    {
        try {
            $this->api->signOut();
        } catch (ApiException) {
            // Offline, expired or already revoked: signing out locally is what matters.
        }

        $this->clearLocal();
    }

    public function clearLocal(): void
    {
        $this->location->stopLocal();
        $this->tokens->forget();
        $this->tokens->clearPending();
        $this->cache->wipe();
        $this->push->clear();
        $this->clearIconBadge();
    }

    /**
     * A signed-out phone has no unread alerts to count, so its icon number goes to zero, and the previous
     * account's notifications must not stay in the Android tray (the plugin's clearBadge() is cancelAll()
     * there; on iOS it is a harmless second zero). Each call is guarded on its own: one failing never skips
     * the other, and neither stops the sign-out.
     */
    private function clearIconBadge(): void
    {
        try {
            $this->badge->clear();
        } catch (Throwable $e) {
            Log::info('App badge clear on sign-out failed: '.$e::class);
        }

        try {
            PushNotifications::clearBadge();
        } catch (Throwable $e) {
            Log::info('Push tray clear on sign-out failed: '.$e::class);
        }
    }
}
