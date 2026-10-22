<?php

namespace App\Support\ChannelTypes\Contracts;

/**
 * A channel that texts or calls the subscriber's own number through their own provider
 * account. Its credentials, `from`, `to` (both E.164) and consent record live in the
 * encrypted `config`, never in `apprise_url`. The failure text and the wizard's hints come
 * from ByoChannelType.
 */
interface PhoneChannelType extends ByoChannelType
{
    /**
     * The provider's name as the subscriber knows it ("Twilio"), used in the consent text.
     */
    public function providerLabel(): string;

    /**
     * The opt-out sentence appended to the confirmation text, or null when the provider
     * handles no reply keywords.
     */
    public function optOutHint(): ?string;
}
