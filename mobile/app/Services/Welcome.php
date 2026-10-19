<?php

namespace App\Services;

use App\Exceptions\Api\ApiException;
use App\Support\PushState;
use App\Support\TokenStore;
use App\Support\WelcomeState;

/**
 * Decides whether the welcome flow shows and which of its steps apply. A permission step is left
 * out when it would be pointless: push already on or refused by the OS, location already shared or
 * with no free phone slot on the plan. A failed slot lookup counts as "no slot"; nothing here throws.
 */
class Welcome
{
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
        $steps = ['welcome', 'offer'];

        if (! $this->push->wantsPush() && $this->registration->permission() !== 'denied') {
            $steps[] = 'push';
        }

        if ($this->locationSlotIsFree()) {
            $steps[] = 'location';
        }

        $steps[] = 'done';

        return $steps;
    }

    /** Sharing is off and the server has a phone slot free; the server is asked only when sharing is off. */
    private function locationSlotIsFree(): bool
    {
        if ($this->location->state()['status'] !== 'off') {
            return false;
        }

        try {
            return ($this->api->alertRuleOptions()['can_share_phone_location'] ?? false) === true;
        } catch (ApiException) {
            return false;
        }
    }
}
