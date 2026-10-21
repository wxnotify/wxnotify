<?php

namespace App\Support\ChannelTypes\Contracts;

use App\Models\NotificationChannel;

/**
 * A channel that texts or calls the subscriber's own number through their own provider
 * account. Its credentials, `from`, `to` (both E.164) and consent record live in the
 * encrypted `config`, never in `apprise_url`.
 */
interface PhoneChannelType extends ChannelType
{
    /**
     * The provider's name as the subscriber knows it ("Twilio"), used in the consent text.
     */
    public function providerLabel(): string;

    /**
     * The fixed text recorded when the provider does not accept a send. Never built from
     * a provider or Apprise response.
     */
    public function failureMessage(): string;

    /**
     * The opt-out sentence appended to the confirmation text, or null when the provider
     * handles no reply keywords.
     */
    public function optOutHint(): ?string;

    /**
     * What the wizard says after a code was sent to the channel's number.
     */
    public function confirmationHint(NotificationChannel $channel): string;

    /**
     * What the wizard says when the code could not be sent: usually to check the
     * credentials and numbers, not that the service is unavailable.
     */
    public function codeNotSentHint(): string;
}
