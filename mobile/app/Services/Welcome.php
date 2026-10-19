<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\PushState;
use App\Support\TokenStore;
use App\Support\WelcomeState;
use Throwable;

/**
 * Decides whether the welcome flow shows and which of its steps apply. A permission step is left
 * out when it would be pointless: push already on or refused by the OS, location already shared or
 * with no free phone slot on the plan. A failed slot lookup counts as "no slot"; nothing here throws.
 */
class Welcome
{
    /** The slot lookup runs while the first screen waits: a slower answer counts as unknown. */
    private const SLOT_LOOKUP_TIMEOUT_SECONDS = 4;

    private ?bool $phoneSlot = null;

    public function __construct(
        private WelcomeState $state,
        private PushState $push,
        private PushRegistration $registration,
        private LocationSharing $location,
        private WxNotifyApi $api,
        private TokenStore $tokens,
    ) {}

    public function shouldShow(): bool
    {
        return ! $this->state->seen();
    }

    /** An install that was already signed in before the flow existed never sees it. */
    public function markLegacyInstallSeen(): void
    {
        if (! $this->state->seen() && $this->tokens->token() !== null) {
            $this->state->markSeen();
        }
    }

    /**
     * @return list<'welcome'|'offer'|'push'|'location'|'done'>
     */
    public function steps(): array
    {
        $this->phoneSlot = null;
        $steps = ['welcome', 'offer'];

        if ($this->pushStepApplies()) {
            $steps[] = 'push';
        }

        if ($this->locationSlotIsFree()) {
            $steps[] = 'location';
        }

        $steps[] = 'done';

        return $steps;
    }

    /**
     * What the last steps() learned from the server about a free phone-location slot: true or
     * false when it answered, null when it was not asked (sharing not off), failed, timed out or
     * answered without the key (a backend older than the app).
     */
    public function phoneSlot(): ?bool
    {
        return $this->phoneSlot;
    }

    /** Push is not already on and the OS has not refused it; a native failure leaves the step out. */
    private function pushStepApplies(): bool
    {
        try {
            return ! $this->push->wantsPush() && $this->registration->permission() !== 'denied';
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Sharing is off and the server has a phone slot free; the server is asked only when sharing is
     * off ("waiting" counts as on), with a short timeout so a slow answer never holds up the screen.
     */
    private function locationSlotIsFree(): bool
    {
        try {
            if ($this->location->state()['status'] !== 'off') {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        try {
            $answer = $this->api->alertRuleOptions(self::SLOT_LOOKUP_TIMEOUT_SECONDS)['can_share_phone_location'] ?? null;
        } catch (ApiException) {
            return false;
        }

        $this->phoneSlot = is_bool($answer) ? $answer : null;

        return $this->phoneSlot === true;
    }
}
