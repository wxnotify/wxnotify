<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Exceptions\Api\Unauthenticated;
use App\Support\PushState;
use Native\Mobile\Facades\PushNotifications;
use Native\Mobile\Facades\System;

/**
 * Everything the app does about push: turning it on and off, and keeping the
 * server's copy of the FCM token current. Registering never throws at the screens: a server hiccup
 * leaves the token to be sent on a later launch.
 */
class PushRegistration
{
    /** Enroll again on launch when the last registration is older than this (hours). */
    private const REFRESH_AFTER_HOURS = 20;

    public function __construct(private WxNotifyApi $api, private PushState $state) {}

    /**
     * "granted", "denied", "not_determined", "provisional" or "ephemeral"; null off a device.
     */
    public function permission(): ?string
    {
        return PushNotifications::checkPermission();
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
     *
     * @throws Unauthenticated when the server no longer accepts this sign-in; the caller signs out
     */
    public function disable(): bool
    {
        try {
            $this->api->unregisterPushToken();
        } catch (Unauthenticated $e) {
            throw $e;
        } catch (ApiException) {
            return false;
        }

        $this->state->markDisabled();

        return true;
    }

    /**
     * Sends the token while push is on. It deliberately ignores the OS permission: Android 13+ fires
     * the token event before the dialog answer is recorded, so checkPermission() still reads
     * "denied" for the very registration that should be made.
     */
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
     * On launch while push is on and the OS allows notifications: when the server has not been sent
     * a token today, ask the OS to enroll again. That shows no dialog (permission is already given),
     * fetches the live FCM token and fires TokenGenerated, which the mounted screen's handler (HandlesPushToken) sends
     * to the server (recreating a channel it pruned). The plugin's cached getToken() value is never
     * sent: it is not kept current, and on iOS it can hold the APNs token.
     */
    public function refreshIfDue(): void
    {
        if (! $this->state->wantsPush() || $this->state->registeredWithin(self::REFRESH_AFTER_HOURS)) {
            return;
        }

        if (in_array($this->permission(), ['granted', 'provisional'], true)) {
            PushNotifications::enroll();
        }
    }
}
