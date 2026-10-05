<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\PushState;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

/**
 * Everything the app does about push: when to prompt, turning it on and off, and keeping the
 * server's copy of the FCM token current. Registering never throws at the screens: a server hiccup
 * leaves the token to be sent on a later launch.
 */
class PushRegistration
{
    /** Re-send the token on launch when the last send is older than this (hours). */
    private const REFRESH_AFTER_HOURS = 20;

    public function __construct(private WxNotifyApi $api, private PushState $state) {}

    /**
     * "granted", "denied", "not_determined", "provisional" or "ephemeral"; null off a device.
     */
    public function permission(): ?string
    {
        return PushNotifications::checkPermission();
    }

    public function shouldPrompt(): bool
    {
        return ! $this->state->declined()
            && ! $this->state->wantsPush()
            && in_array($this->permission(), ['not_determined', 'granted', 'provisional'], true);
    }

    public function enable(): void
    {
        $this->state->markEnabled();

        PushNotifications::enroll();
    }

    public function decline(): void
    {
        $this->state->markDeclined();
    }

    /**
     * @return bool false when the server could not be told, in which case push stays on
     */
    public function disable(): bool
    {
        try {
            $this->api->unregisterPushToken();
        } catch (ApiException) {
            return false;
        }

        $this->state->markDisabled();

        return true;
    }

    public function register(string $token): void
    {
        if (! $this->state->wantsPush()) {
            return;
        }

        try {
            $this->api->registerPushToken($token, System::isIos() ? 'ios' : 'android');
        } catch (ApiException) {
            return;
        }

        $this->state->markRegistered();
    }

    /**
     * On launch while push is on: send the token again if it has not been sent today, which also
     * recreates a channel the server pruned.
     */
    public function refreshIfDue(): void
    {
        if (! $this->state->wantsPush() || $this->state->registeredWithin(self::REFRESH_AFTER_HOURS)) {
            return;
        }

        $token = PushNotifications::getToken();

        if ($token !== null) {
            $this->register($token);
        }
    }
}
