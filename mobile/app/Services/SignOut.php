<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\LocalCache;
use App\Support\PushState;
use App\Support\TokenStore;

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
    }
}
